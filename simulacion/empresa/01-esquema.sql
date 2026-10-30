-- =====================================================================
-- Empresa simulada (SQL Server) — esquema, vistas y usuario de solo lectura
--
-- Imita la base de la planta: nombres propios de la empresa y columnas que
-- la app NO necesita (Operador, Observaciones...). El sincronizador solo ve
-- el esquema `integracion`, nunca las tablas de `dbo`.
--
-- Idempotente: se puede ejecutar varias veces sin duplicar nada.
-- Variable sqlcmd requerida: LECTOR_PASS (llega por variable de entorno).
-- =====================================================================

IF DB_ID(N'planta_empresa') IS NULL
    CREATE DATABASE planta_empresa;
GO

USE planta_empresa;
GO

-- ---------------------------------------------------------------------
-- Catálogos
-- ---------------------------------------------------------------------
IF OBJECT_ID(N'dbo.Especies') IS NULL
CREATE TABLE dbo.Especies (
    EspecieID INT           NOT NULL CONSTRAINT PK_Especies PRIMARY KEY,
    Nombre    NVARCHAR(50)  NOT NULL
);

IF OBJECT_ID(N'dbo.Tallas') IS NULL
CREATE TABLE dbo.Tallas (
    TallaID   INT           NOT NULL CONSTRAINT PK_Tallas PRIMARY KEY,
    Rango     NVARCHAR(20)  NOT NULL,
    Estandar  DECIMAL(5,4)  NOT NULL
);

IF OBJECT_ID(N'dbo.Barcos') IS NULL
CREATE TABLE dbo.Barcos (
    BarcoID     INT           NOT NULL CONSTRAINT PK_Barcos PRIMARY KEY,
    Nombre      NVARCHAR(100) NOT NULL,
    Abreviatura NVARCHAR(3)   NOT NULL
);
GO

-- ---------------------------------------------------------------------
-- Partidas: tabla operativa de la planta.
-- Simula los dos mecanismos incrementales:
--   PartidaID          -> ID creciente (IDENTITY)
--   FechaModificacion  -> se actualiza sola en cada UPDATE (trigger)
-- ---------------------------------------------------------------------
IF OBJECT_ID(N'dbo.Partidas') IS NULL
CREATE TABLE dbo.Partidas (
    PartidaID         INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Partidas PRIMARY KEY CLUSTERED,
    CodigoCompra      VARCHAR(20)    NOT NULL,
    FechaProceso      DATETIME2(0)   NOT NULL,
    BarcoID           INT            NOT NULL CONSTRAINT FK_Partidas_Barcos   REFERENCES dbo.Barcos(BarcoID),
    EspecieID         INT            NOT NULL CONSTRAINT FK_Partidas_Especies REFERENCES dbo.Especies(EspecieID),
    TallaID           INT            NOT NULL CONSTRAINT FK_Partidas_Tallas   REFERENCES dbo.Tallas(TallaID),
    PesoNetoKg        DECIMAL(10,2)  NULL,
    Rendimiento       DECIMAL(5,4)   NOT NULL,
    Turno             CHAR(1)        NULL,
    Operador          NVARCHAR(100)  NULL,
    Observaciones     NVARCHAR(500)  NULL,
    FechaCreacion     DATETIME2(3)   NOT NULL CONSTRAINT DF_Partidas_FechaCreacion     DEFAULT SYSUTCDATETIME(),
    FechaModificacion DATETIME2(3)   NOT NULL CONSTRAINT DF_Partidas_FechaModificacion DEFAULT SYSUTCDATETIME()
);
GO

-- Índices sobre las columnas que filtra el sincronizador
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_Partidas_FechaModificacion')
    CREATE INDEX IX_Partidas_FechaModificacion ON dbo.Partidas (FechaModificacion, PartidaID);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_Partidas_FechaProceso')
    CREATE INDEX IX_Partidas_FechaProceso ON dbo.Partidas (FechaProceso);
GO

CREATE OR ALTER TRIGGER dbo.TR_Partidas_FechaModificacion
ON dbo.Partidas
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE p
       SET FechaModificacion = SYSUTCDATETIME()
      FROM dbo.Partidas p
      JOIN inserted i ON i.PartidaID = p.PartidaID;
END;
GO

-- ---------------------------------------------------------------------
-- Datos de catálogo (iguales a los de la app para la simulación)
-- ---------------------------------------------------------------------
MERGE dbo.Especies AS t
USING (VALUES
    (1, N'YELLOWFIN'),
    (2, N'BIGEYE'),
    (3, N'SKIPJACK')
) AS s (EspecieID, Nombre)
ON t.EspecieID = s.EspecieID
WHEN MATCHED THEN UPDATE SET Nombre = s.Nombre
WHEN NOT MATCHED THEN INSERT (EspecieID, Nombre) VALUES (s.EspecieID, s.Nombre);

MERGE dbo.Tallas AS t
USING (VALUES
    (1, N'-1.4', 0.4100),
    (2, N'1.4-1.8', 0.4150),
    (3, N'1.8-3.4', 0.4300),
    (4, N'3.4-10', 0.4400),
    (5, N'10-20', 0.4700),
    (6, N'20-30', 0.4700),
    (7, N'30-40', 0.4800),
    (8, N'40-50', 0.4800),
    (9, N'50-60', 0.4850),
    (10, N'60-70', 0.4850),
    (11, N'70-80', 0.4850),
    (13, N'1.8-2.5', 0.4200),
    (14, N'2.5-3.4', 0.4300),
    (15, N'3.4-5.4', 0.4400),
    (16, N'5.4-7.3', 0.4500),
    (17, N'7.3-10', 0.4600)
) AS s (TallaID, Rango, Estandar)
ON t.TallaID = s.TallaID
WHEN MATCHED THEN UPDATE SET Rango = s.Rango, Estandar = s.Estandar
WHEN NOT MATCHED THEN INSERT (TallaID, Rango, Estandar) VALUES (s.TallaID, s.Rango, s.Estandar);

MERGE dbo.Barcos AS t
USING (VALUES
    (1, N'SANT YAGO UNO', N'SY1'),
    (2, N'ALBACORA CARIBE', N'ACA'),
    (3, N'ALBACORA NUEVE', N'AL9'),
    (4, N'ALBACORA QUINCE', N'A15'),
    (5, N'DRACO', N'DRA'),
    (6, N'INTERTUNA TRES', N'IN3'),
    (7, N'ALBATUN DOS', N'AB2'),
    (8, N'ALBACORA UNO', N'AL1'),
    (9, N'GALERNA TRES', N'GA3'),
    (10, N'ALBACAN', N'ALB'),
    (11, N'PACIFIC STAR', N'PST'),
    (12, N'GALERNA DOS', N'GA2'),
    (13, N'ALBACORA DIEZ', N'A10'),
    (14, N'TALENDUIC', N'TAL'),
    (15, N'CAYO CRASQUI', N'CCR'),
    (16, N'GALERNA LAU', N'GLA'),
    (17, N'MARIETA', N'MRT'),
    (18, N'ROBISON CRUSOE', N'RCR'),
    (19, N'CAYO NORONKY', N'CNO'),
    (20, N'ALBACORA CUATRO', N'AL4'),
    (21, N'ALETA AZUL', N'AAZ'),
    (22, N'ALTAR 21', N'A21'),
    (23, N'ARKOS I CHIAPAS', N'AIC'),
    (24, N'BARAKA', N'BAR'),
    (25, N'CARIBE TUNA', N'CTU'),
    (26, N'DIVA MARIA', N'DMR'),
    (27, N'GRAN ROQUE', N'GRO'),
    (28, N'ISABELLA', N'ISB'),
    (29, N'JORGE MARIO', N'JMI'),
    (30, N'LA PEÑA', N'LPE'),
    (31, N'UPAR', N'UPA'),
    (32, N'LAUTARO', N'LAU'),
    (33, N'LJBICA', N'LJU'),
    (34, N'LOS ROQUES', N'LRO'),
    (35, N'NAIR', N'NAI'),
    (36, N'ORIENTAL KIM', N'OKI'),
    (37, N'PS-1', N'PS1'),
    (38, N'SOLOMON OPAL', N'SOP'),
    (39, N'SOUTHERN SEAS 301', N'SS3'),
    (40, N'SOUTHERN SEAS 302', N'SS0'),
    (41, N'SOUTHERN SEAS 303', N'SE3'),
    (42, N'TAURUS 1', N'TAS'),
    (43, N'TEMPLARIO 1', N'TEM'),
    (44, N'TIUNA', N'TIU'),
    (45, N'VIA MISTRAL', N'VMI'),
    (46, N'VICTORIA', N'VIT'),
    (47, N'WESTERN KIM', N'WKI'),
    (48, N'XIXILI', N'XIX'),
    (49, N'MADEIRA', N'MDR'),
    (50, N'CLARION', N'CLR'),
    (51, N'EUROS TUNA', N'ETU'),
    (52, N'PAPA RESTY', N'PRY'),
    (53, N'SEA BREEZE', N'SEA'),
    (54, N'JASMIN 888', N'JAS'),
    (55, N'CONQUISTA', N'CQT'),
    (56, N'BELINDA', N'BLD'),
    (57, N'ALEJANDRA', N'ALJ'),
    (58, N'CHACAO', N'CHC'),
    (59, N'PESQUERO GENÉRICO PEQUEÑOS PROVEEDORES', N'PPP')
) AS s (BarcoID, Nombre, Abreviatura)
ON t.BarcoID = s.BarcoID
WHEN MATCHED THEN UPDATE SET Nombre = s.Nombre, Abreviatura = s.Abreviatura
WHEN NOT MATCHED THEN INSERT (BarcoID, Nombre, Abreviatura) VALUES (s.BarcoID, s.Nombre, s.Abreviatura);
GO

-- ---------------------------------------------------------------------
-- Esquema de integración: lo ÚNICO que ve el sincronizador.
-- Expone solo las columnas que usa la app (sin Operador, Observaciones,
-- Turno ni PesoNeto). La alerta no se expone: la app la calcula
-- comparando Rendimiento contra el estándar de la talla.
-- ---------------------------------------------------------------------
IF SCHEMA_ID(N'integracion') IS NULL
    EXEC (N'CREATE SCHEMA integracion AUTHORIZATION dbo');
GO

CREATE OR ALTER VIEW integracion.vw_partidas AS
SELECT PartidaID,
       CodigoCompra,
       FechaProceso,
       BarcoID,
       EspecieID,
       TallaID,
       Rendimiento,
       FechaModificacion
  FROM dbo.Partidas;
GO

CREATE OR ALTER VIEW integracion.vw_especies AS
SELECT EspecieID, Nombre FROM dbo.Especies;
GO

CREATE OR ALTER VIEW integracion.vw_tallas AS
SELECT TallaID, Rango, Estandar FROM dbo.Tallas;
GO

CREATE OR ALTER VIEW integracion.vw_barcos AS
SELECT BarcoID, Nombre, Abreviatura FROM dbo.Barcos;
GO

-- ---------------------------------------------------------------------
-- Usuario de solo lectura para el sincronizador (mínimo privilegio):
-- SELECT sobre las vistas de `integracion` y nada sobre `dbo`.
-- ---------------------------------------------------------------------
USE master;
GO
IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'sync_lector')
    CREATE LOGIN sync_lector WITH PASSWORD = N'$(LECTOR_PASS)', CHECK_POLICY = ON, DEFAULT_DATABASE = planta_empresa;
ELSE
    ALTER LOGIN sync_lector WITH PASSWORD = N'$(LECTOR_PASS)';
GO

USE planta_empresa;
GO
IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = N'sync_lector')
    CREATE USER sync_lector FOR LOGIN sync_lector;
GO
GRANT SELECT ON SCHEMA::integracion TO sync_lector;
DENY  SELECT, INSERT, UPDATE, DELETE, EXECUTE ON SCHEMA::dbo TO sync_lector;
GO

PRINT N'Empresa simulada lista: planta_empresa + integracion.* + sync_lector';
GO
