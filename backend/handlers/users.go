package handlers

import (
	"database/sql"
	"net/http"

	"github.com/gin-gonic/gin"

	"fidepaz.org/backend/database"
)

// ListUsers — GET /api/v2/users (solo admin/super_admin). Nunca expone la columna `password`.
func ListUsers(c *gin.Context) {
	rows, err := database.DB.Query(`
		SELECT id, email, name, code, phone, cellphone, role, createAt
		FROM ` + "`user`" + `
		WHERE deleteAt IS NULL
		ORDER BY name`)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error consultando colonos"})
		return
	}
	defer rows.Close()

	type user struct {
		ID        int            `json:"id"`
		Email     string         `json:"email"`
		Name      string         `json:"name"`
		Code      sql.NullString `json:"code"`
		Phone     sql.NullString `json:"phone"`
		Cellphone sql.NullString `json:"cellphone"`
		Role      string         `json:"role"`
		CreateAt  string         `json:"createAt"`
	}

	data := []user{}
	for rows.Next() {
		var u user
		if err := rows.Scan(&u.ID, &u.Email, &u.Name, &u.Code, &u.Phone, &u.Cellphone, &u.Role, &u.CreateAt); err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error leyendo resultados"})
			return
		}
		data = append(data, u)
	}

	c.JSON(http.StatusOK, gin.H{"status": "ok", "data": data, "items": data, "meta": gin.H{"total": len(data)}})
}

// Catalog — GET /api/v2/catalog/:which (which = "streets" | "quotas")
func Catalog(c *gin.Context) {
	which := c.Param("which")

	var table string
	switch which {
	case "streets":
		table = "street"
	case "quotas":
		table = "quota"
	default:
		c.JSON(http.StatusNotFound, gin.H{"status": "error", "message": "Catálogo no encontrado"})
		return
	}

	// `table` sale de un switch fijo (whitelist), nunca de input libre del cliente -> no hay inyección posible.
	rows, err := database.DB.Query("SELECT * FROM `" + table + "` ORDER BY id")
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error consultando catálogo"})
		return
	}
	defer rows.Close()

	cols, err := rows.Columns()
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error leyendo columnas"})
		return
	}

	data := []map[string]interface{}{}
	for rows.Next() {
		values := make([]interface{}, len(cols))
		ptrs := make([]interface{}, len(cols))
		for i := range values {
			ptrs[i] = &values[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error leyendo resultados"})
			return
		}
		row := map[string]interface{}{}
		for i, col := range cols {
			if b, ok := values[i].([]byte); ok {
				row[col] = string(b)
			} else {
				row[col] = values[i]
			}
		}
		data = append(data, row)
	}

	c.JSON(http.StatusOK, gin.H{"status": "ok", "data": data, "items": data, "meta": gin.H{"total": len(data)}})
}
