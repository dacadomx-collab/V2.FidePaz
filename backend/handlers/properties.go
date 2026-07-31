package handlers

import (
	"database/sql"
	"net/http"

	"github.com/gin-gonic/gin"

	"fidepaz.org/backend/database"
)

// ListProperties — GET /api/v2/properties
func ListProperties(c *gin.Context) {
	rows, err := database.DB.Query(`
		SELECT p.id, p.numOficial, p.due_day, s.name AS street_name, q.name AS quota_name, q.cost AS quota_cost
		FROM property p
		LEFT JOIN street s ON s.id = p.street_id
		LEFT JOIN quota  q ON q.id = p.quota_id
		WHERE p.deleteAt IS NULL
		ORDER BY s.name, p.numOficial`)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error consultando propiedades"})
		return
	}
	defer rows.Close()

	type property struct {
		ID         int             `json:"id"`
		NumOficial int             `json:"numOficial"`
		DueDay     int             `json:"due_day"`
		StreetName sql.NullString  `json:"street_name"`
		QuotaName  sql.NullString  `json:"quota_name"`
		QuotaCost  sql.NullFloat64 `json:"quota_cost"`
	}

	data := []property{}
	for rows.Next() {
		var p property
		if err := rows.Scan(&p.ID, &p.NumOficial, &p.DueDay, &p.StreetName, &p.QuotaName, &p.QuotaCost); err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error leyendo resultados"})
			return
		}
		data = append(data, p)
	}

	c.JSON(http.StatusOK, gin.H{"status": "ok", "data": data})
}
