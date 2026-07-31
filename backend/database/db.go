package database

import (
	"database/sql"
	"fmt"
	"log"
	"os"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

// DB es el pool de conexiones compartido por todos los handlers.
// Nunca se construyen queries por concatenación: todo pasa por
// Prepared Statements con placeholders "?" del driver MySQL.
var DB *sql.DB

func Init() {
	host := mustEnv("DB_HOST")
	port := envOr("DB_PORT", "3306")
	name := mustEnv("DB_NAME")
	user := mustEnv("DB_USER")
	pass := mustEnv("DB_PASS")

	dsn := fmt.Sprintf(
		"%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=true&loc=Local",
		user, pass, host, port, name,
	)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Fatalf("no se pudo abrir el pool de MySQL: %v", err)
	}

	db.SetMaxOpenConns(20)
	db.SetMaxIdleConns(10)
	db.SetConnMaxLifetime(5 * time.Minute)

	if err := db.Ping(); err != nil {
		log.Fatalf("no se pudo conectar a MySQL (%s@%s:%s/%s): %v", user, host, port, name, err)
	}

	DB = db
	log.Printf("MySQL conectado: %s/%s", host, name)
}

func mustEnv(key string) string {
	v := os.Getenv(key)
	if v == "" {
		log.Fatalf("falta variable de entorno requerida: %s", key)
	}
	return v
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
