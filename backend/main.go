// Servidor API + estático de FidePaz v2.0.
// Reemplaza por completo la dependencia de Google Cloud Run: este binario
// corre en el mismo servidor que sirve el frontend, sin egress externo,
// sin CORS cross-origin real y sin timeouts de red intermitentes.
package main

import (
	"log"
	"net/http"
	"os"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/joho/godotenv"

	"fidepaz.org/backend/database"
	"fidepaz.org/backend/handlers"
	"fidepaz.org/backend/middleware"
)

func main() {
	if err := godotenv.Load(); err != nil {
		log.Println("aviso: no se encontró .env, se usarán variables de entorno del sistema")
	}

	database.Init()

	if os.Getenv("GIN_MODE") == "" {
		gin.SetMode(gin.ReleaseMode)
	}

	router := gin.New()
	router.Use(gin.Recovery(), gin.Logger())
	router.Use(middleware.CORS())

	api := router.Group("/api/v2")
	{
		api.GET("", apiStatus)
		api.GET("/", apiStatus)

		api.POST("/auth/login", middleware.RateLimit(10, 5*time.Minute), handlers.Login)
		api.POST("/contact", middleware.RateLimit(5, 10*time.Minute), handlers.Contact)

		api.GET("/properties", middleware.RequireAuth(), handlers.ListProperties)
		api.GET("/user-quotas", middleware.RequireAuth(), handlers.ListUserQuotas)
		api.GET("/users", middleware.RequireAuth(), middleware.RequireRole("admin", "super_admin"), handlers.ListUsers)
		api.GET("/catalog/:which", middleware.RequireAuth(), handlers.Catalog)
	}

	// Servidor estático embebido: permite correr el binario de forma
	// independiente (sin Apache/Nginx delante) sirviendo directamente
	// el build de Angular en ../administrator. Si se despliega detrás
	// de Apache/cPanel, esta parte es opcional (Apache sirve los
	// estáticos y solo reenvía /api/v2 a este proceso).
	staticDir := envOr("STATIC_DIR", "../administrator")
	if info, err := os.Stat(staticDir); err == nil && info.IsDir() {
		router.Use(gin.Logger())
		router.Static("/assets", staticDir)
		router.NoRoute(func(c *gin.Context) {
			c.File(staticDir + "/index.html") // fallback SPA (rutas Angular del lado cliente)
		})
		router.StaticFile("/", staticDir+"/index.html")
	}

	port := envOr("PORT", "8080")
	log.Printf("FidePaz v2.0 backend escuchando en :%s (prefijo /api/v2)", port)
	if err := router.Run(":" + port); err != nil {
		log.Fatalf("error iniciando servidor: %v", err)
	}
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

// apiStatus — GET /api/v2 y GET /api/v2/. Evita el 404 plano de Gin cuando se
// navega directamente a la raíz del prefijo (p. ej. health check manual).
func apiStatus(c *gin.Context) {
	c.JSON(http.StatusOK, gin.H{
		"status":      "ok",
		"system":      "FidePaz Core API v2.0",
		"environment": envOr("APP_ENV", "development"),
	})
}
