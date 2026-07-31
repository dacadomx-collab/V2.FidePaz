# FidePaz v2.0 — Proyecto limpio

Raíz de trabajo activa. `BackUp_28_Julio_2026/` es **solo lectura** — fuente
de datos (`mercagee_colonoscore.sql`) y assets de la V1, nada se escribe ahí.

## Estructura

```
FidePaz.org/
├── backend/        Go 1.26 (Gin) — API REST bajo /api/v2
├── db/             schema.sql (DDL sanitizado) + seed_data.sql (datos reales)
├── administrator/  Build Angular del panel (SPA), apunta a /api/v2
└── FIDEPAZ_V1_VS_V2_COMPARISON.md
```

## Arranque local (XAMPP)

```bash
# 1. Base de datos
mysql -u root -p < db/schema.sql
mysql -u root -p < db/seed_data.sql

# 2. Backend
cd backend
cp .env.example .env        # editar credenciales y JWT_SECRET
go mod tidy
go build -o fidepaz-backend.exe .
./fidepaz-backend.exe        # escucha en :8080, prefijo /api/v2

# 3. Frontend
# Servir administrator/ con Apache de XAMPP, o dejar que el propio binario
# lo sirva si STATIC_DIR apunta a ../administrator (ver main.go).
```

Detalles de la migración de datos y las decisiones de diseño del esquema:
ver [`db/README.md`](db/README.md). Comparativa técnica completa para la
junta: [`FIDEPAZ_V1_VS_V2_COMPARISON.md`](FIDEPAZ_V1_VS_V2_COMPARISON.md).
