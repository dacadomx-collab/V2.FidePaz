package middleware

import (
	"net/http"
	"sync"
	"time"

	"github.com/gin-gonic/gin"
)

type bucket struct {
	count       int
	windowStart time.Time
}

// RateLimit limita intentos por IP en memoria (proceso único de Go —
// no necesita Redis ni archivos temporales como en el proxy PHP anterior).
func RateLimit(maxAttempts int, window time.Duration) gin.HandlerFunc {
	var mu sync.Mutex
	buckets := map[string]*bucket{}

	return func(c *gin.Context) {
		ip := c.ClientIP()

		mu.Lock()
		b, ok := buckets[ip]
		now := time.Now()
		if !ok || now.Sub(b.windowStart) > window {
			b = &bucket{count: 0, windowStart: now}
			buckets[ip] = b
		}
		b.count++
		exceeded := b.count > maxAttempts
		mu.Unlock()

		if exceeded {
			c.AbortWithStatusJSON(http.StatusTooManyRequests, gin.H{
				"status":  "error",
				"message": "Demasiados intentos. Intenta de nuevo en unos minutos.",
			})
			return
		}
		c.Next()
	}
}
