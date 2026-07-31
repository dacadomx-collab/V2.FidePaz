package middleware

import (
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/golang-jwt/jwt/v5"
)

type Claims struct {
	UserID int    `json:"sub"`
	Email  string `json:"email"`
	Role   string `json:"role"`
	jwt.RegisteredClaims
}

// IssueToken firma un JWT HS256 válido por 12 horas (requisito de negocio: turnos administrativos largos).
func IssueToken(userID int, email, role string) (string, error) {
	secret := os.Getenv("JWT_SECRET")
	claims := Claims{
		UserID: userID,
		Email:  email,
		Role:   role,
		RegisteredClaims: jwt.RegisteredClaims{
			IssuedAt:  jwt.NewNumericDate(time.Now()),
			ExpiresAt: jwt.NewNumericDate(time.Now().Add(12 * time.Hour)),
		},
	}
	token := jwt.NewWithClaims(jwt.SigningMethodHS256, claims)
	return token.SignedString([]byte(secret))
}

// RequireAuth valida el Bearer token y deja las claims en el contexto ("claims").
// Zero Trust: cada request protegido se verifica de forma independiente, sin sesiones de servidor.
func RequireAuth() gin.HandlerFunc {
	return func(c *gin.Context) {
		header := c.GetHeader("Authorization")
		parts := strings.SplitN(header, " ", 2)
		if len(parts) != 2 || !strings.EqualFold(parts[0], "Bearer") {
			c.AbortWithStatusJSON(http.StatusUnauthorized, gin.H{"status": "error", "message": "Token de autenticación no proporcionado"})
			return
		}

		secret := os.Getenv("JWT_SECRET")
		claims := &Claims{}
		token, err := jwt.ParseWithClaims(parts[1], claims, func(t *jwt.Token) (interface{}, error) {
			if _, ok := t.Method.(*jwt.SigningMethodHMAC); !ok {
				return nil, jwt.ErrSignatureInvalid
			}
			return []byte(secret), nil
		})
		if err != nil || !token.Valid {
			c.AbortWithStatusJSON(http.StatusUnauthorized, gin.H{"status": "error", "message": "Token inválido o expirado"})
			return
		}

		c.Set("claims", claims)
		c.Next()
	}
}

// RequireRole corta con 403 si el rol del token no está en la lista permitida. Debe ir después de RequireAuth().
func RequireRole(roles ...string) gin.HandlerFunc {
	allowed := map[string]bool{}
	for _, r := range roles {
		allowed[r] = true
	}
	return func(c *gin.Context) {
		claims := c.MustGet("claims").(*Claims)
		if !allowed[claims.Role] {
			c.AbortWithStatusJSON(http.StatusForbidden, gin.H{"status": "error", "message": "No tienes permisos para esta operación"})
			return
		}
		c.Next()
	}
}
