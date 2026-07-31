package handlers

import (
	"html"
	"net/http"
	"regexp"
	"strings"

	"github.com/gin-gonic/gin"

	"fidepaz.org/backend/database"
)

var (
	contactEmailRe = regexp.MustCompile(`^[^\s@]+@[^\s@]+\.[^\s@]+$`)
	htmlTagRe      = regexp.MustCompile(`<[^>]*>`)
)

type contactRequest struct {
	Name    string `json:"name"`
	Email   string `json:"email"`
	Message string `json:"message"`
}

// sanitizeContactField quita etiquetas HTML y escapa entidades — defensa en
// profundidad además de la sanitización ya aplicada en el cliente
// (assets/js/main.js), ya que el cliente nunca es de confianza.
func sanitizeContactField(raw string) string {
	stripped := htmlTagRe.ReplaceAllString(raw, "")
	return html.EscapeString(strings.TrimSpace(stripped))
}

// Contact — POST /api/v2/contact
// Endpoint público (sin JWT, como /auth/login) protegido por rate limit.
func Contact(c *gin.Context) {
	var req contactRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"status": "error", "message": "Payload inválido"})
		return
	}

	name := sanitizeContactField(req.Name)
	email := sanitizeContactField(req.Email)
	message := sanitizeContactField(req.Message)

	if name == "" || email == "" || message == "" {
		c.JSON(http.StatusBadRequest, gin.H{"status": "error", "message": "Nombre, correo y mensaje son requeridos"})
		return
	}
	if len(name) > 100 || len(email) > 150 || len(message) > 1000 {
		c.JSON(http.StatusBadRequest, gin.H{"status": "error", "message": "Uno o más campos exceden la longitud permitida"})
		return
	}
	if !contactEmailRe.MatchString(email) {
		c.JSON(http.StatusBadRequest, gin.H{"status": "error", "message": "Correo electrónico inválido"})
		return
	}

	_, err := database.DB.Exec(
		"INSERT INTO contact_messages (name, email, message, ip_address) VALUES (?, ?, ?, ?)",
		name, email, message, c.ClientIP(),
	)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "No se pudo registrar el mensaje"})
		return
	}

	c.JSON(http.StatusOK, gin.H{"status": "ok", "message": "Mensaje recibido"})
}
