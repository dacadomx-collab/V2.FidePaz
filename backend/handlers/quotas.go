package handlers

import (
	"database/sql"
	"net/http"
	"strconv"
	"strings"

	"github.com/gin-gonic/gin"

	"fidepaz.org/backend/database"
	"fidepaz.org/backend/middleware"
)

// ListUserQuotas — GET /api/v2/user-quotas?property_id=&user_id=&status=&from=&to=&limit=&offset=
// Todos los filtros son opcionales; usa los índices compuestos de db/schema.sql
// (idx_uq_property_duedate, idx_uq_user_duedate, idx_uq_due_date, idx_uq_pay_date, idx_uq_status).
func ListUserQuotas(c *gin.Context) {
	claims := c.MustGet("claims").(*middleware.Claims)

	where := []string{"1=1"}
	args := []interface{}{}

	// Un colono (role=owner) solo ve sus propias cuotas; solo roles administrativos pueden filtrar por otro user_id.
	if claims.Role == "owner" {
		where = append(where, "uq.user_id = ?")
		args = append(args, claims.UserID)
	} else if v := c.Query("user_id"); v != "" {
		if id, err := strconv.Atoi(v); err == nil {
			where = append(where, "uq.user_id = ?")
			args = append(args, id)
		}
	}

	if v := c.Query("property_id"); v != "" {
		if id, err := strconv.Atoi(v); err == nil {
			where = append(where, "uq.property_id = ?")
			args = append(args, id)
		}
	}
	if v := c.Query("status"); v != "" {
		if st, err := strconv.Atoi(v); err == nil {
			where = append(where, "uq.status = ?")
			args = append(args, st)
		}
	}
	if v := c.Query("from"); v != "" {
		where = append(where, "uq.due_date >= ?")
		args = append(args, v)
	}
	if v := c.Query("to"); v != "" {
		where = append(where, "uq.due_date <= ?")
		args = append(args, v)
	}

	limit := clampInt(c.Query("limit"), 50, 1, 200)
	offset := clampInt(c.Query("offset"), 0, 0, 1_000_000)

	query := `
		SELECT uq.id, uq.due_date, uq.pay_date, uq.status, uq.amount, uq.receipt,
		       uq.user_id, u.name AS user_name, uq.property_id, p.numOficial
		FROM user_quotas uq
		LEFT JOIN ` + "`user`" + ` u ON u.id = uq.user_id
		LEFT JOIN property p ON p.id = uq.property_id
		WHERE ` + strings.Join(where, " AND ") + `
		ORDER BY uq.due_date DESC
		LIMIT ? OFFSET ?`
	args = append(args, limit, offset)

	rows, err := database.DB.Query(query, args...)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error consultando cuotas"})
		return
	}
	defer rows.Close()

	type userQuota struct {
		ID         int            `json:"id"`
		DueDate    string         `json:"due_date"`
		PayDate    sql.NullString `json:"pay_date"`
		Status     int            `json:"status"`
		Amount     float64        `json:"amount"`
		Receipt    sql.NullString `json:"receipt"`
		UserID     sql.NullInt64  `json:"user_id"`
		UserName   sql.NullString `json:"user_name"`
		PropertyID sql.NullInt64  `json:"property_id"`
		NumOficial sql.NullInt64  `json:"numOficial"`
	}

	data := []userQuota{}
	for rows.Next() {
		var q userQuota
		if err := rows.Scan(&q.ID, &q.DueDate, &q.PayDate, &q.Status, &q.Amount, &q.Receipt,
			&q.UserID, &q.UserName, &q.PropertyID, &q.NumOficial); err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"status": "error", "message": "Error leyendo resultados"})
			return
		}
		data = append(data, q)
	}

	c.JSON(http.StatusOK, gin.H{"status": "ok", "data": data})
}

func clampInt(raw string, def, min, max int) int {
	v, err := strconv.Atoi(raw)
	if err != nil {
		v = def
	}
	if v < min {
		v = min
	}
	if v > max {
		v = max
	}
	return v
}
