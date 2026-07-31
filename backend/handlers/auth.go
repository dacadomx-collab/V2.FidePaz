package handlers

import (
	"database/sql"
	"net/http"
	"regexp"

	"github.com/gin-gonic/gin"
	"golang.org/x/crypto/bcrypt"

	"fidepaz.org/backend/database"
	"fidepaz.org/backend/middleware"
)

var emailRe = regexp.MustCompile(`^[^\s@]+@[^\s@]+\.[^\s@]+$`)

type loginRequest struct {
	Email    string `json:"email"`
	Password string `json:"password"`
}

// Login — POST /api/v2/auth/login
// Los hashes de `user.password` son bcrypt $2b$10$... generados originalmente
// por Node/bcryptjs; bcrypt.CompareHashAndPassword de Go los valida sin
// necesidad de rehash (bcrypt es un estándar interoperable entre lenguajes).
func Login(c *gin.Context) {
	var req loginRequest
	if err := c.ShouldBindJSON(&req); err != nil || req.Email == "" || req.Password == "" || !emailRe.MatchString(req.Email) {
		c.JSON(http.StatusBadRequest, gin.H{"status": "error", "message": "Email y contraseña son requeridos"})
		return
	}

	var (
		id       int
		email    string
		name     string
		role     string
		hashPass string
	)
	row := database.DB.QueryRow(
		"SELECT id, email, name, role, password FROM `user` WHERE email = ? AND deleteAt IS NULL LIMIT 1",
		req.Email,
	)
	err := row.Scan(&id, &email, &name, &role, &hashPass)

	// Mensaje idéntico si el usuario no existe o si la contraseña es incorrecta
	// (evita enumeration attack sobre emails registrados).
	invalidCreds := gin.H{"status": "error", "message": "Credenciales inválidas"}
	if err == sql.ErrNoRows {
		c.JSON(http.StatusUnauthorized, invalidCreds)
		return
	}
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error interno del servidor"})
		return
	}
	if bcrypt.CompareHashAndPassword([]byte(hashPass), []byte(req.Password)) != nil {
		c.JSON(http.StatusUnauthorized, invalidCreds)
		return
	}

	token, err := middleware.IssueToken(id, email, role)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "No se pudo emitir el token"})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"status": "ok",
		"token":  token,
		"user": gin.H{
			"id":    id,
			"email": email,
			"name":  name,
			"role":  role,
		},
	})
}
