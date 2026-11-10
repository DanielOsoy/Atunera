-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 25-03-2026 a las 15:22:02
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `industria_atunera`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `especies`
--

CREATE TABLE `especies` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `especies`
--

INSERT INTO `especies` (`id`, `nombre`) VALUES
(1, 'YELLOWFIN'),
(2, 'BIGEYE'),
(3, 'SKIPJACK');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `predicciones`
--

CREATE TABLE `predicciones` (
  `id` int(11) NOT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `dia` varchar(20) DEFAULT NULL,
  `lote` varchar(50) NOT NULL,
  `compra` varchar(50) DEFAULT 'ND',
  `partida` varchar(50) NOT NULL,
  `especie_id` int(11) DEFAULT NULL,
  `talla_id` int(11) DEFAULT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `peso_neto` decimal(10,2) NOT NULL,
  `temperatura` decimal(5,2) NOT NULL,
  `rendimiento_esperado` decimal(5,4) DEFAULT NULL,
  `kg_utilizables` decimal(10,2) DEFAULT NULL,
  `alerta_status` tinyint(1) DEFAULT NULL,
  `total_pt` decimal(10,2) DEFAULT NULL,
  `total_lomo` decimal(10,2) DEFAULT NULL,
  `total_miga` decimal(10,2) DEFAULT NULL,
  `proceso` varchar(50) DEFAULT 'COCIDO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `predicciones`
--

INSERT INTO `predicciones` (`id`, `fecha_registro`, `dia`, `lote`, `compra`, `partida`, `especie_id`, `talla_id`, `proveedor_id`, `peso_neto`, `temperatura`, `rendimiento_esperado`, `kg_utilizables`, `alerta_status`, `total_pt`, `total_lomo`, `total_miga`, `proceso`) VALUES
(1, '2025-06-02 14:30:00', 'lunes', 'LG5-153', 'TISY12455', '429302065', 2, 4, 1, 1585.00, -18.50, 0.4600, 729.10, 0, 701.00, 629.00, 72.00, 'COCIDO'),
(2, '2025-06-03 15:15:00', 'martes', 'LG5-154', 'TISY12455', '429302066', 1, 3, 1, 2150.00, -17.00, 0.4600, 989.00, 0, 950.00, 810.00, 140.00, 'COCIDO'),
(3, '2025-06-04 17:20:00', 'miércoles', 'LG5-155', 'TISY12455', '429302067', 3, 1, 1, 1840.00, -15.00, 0.4416, 812.54, 0, 710.00, 590.00, 120.00, 'COCIDO'),
(4, '2025-06-05 20:00:00', 'jueves', 'LG5-156', 'TISY12455', '429302068', 1, 3, 1, 2200.00, 4.50, 0.4232, 931.04, 1, 850.00, 700.00, 150.00, 'COCIDO'),
(5, '2025-06-06 14:45:00', 'viernes', 'LG5-157', 'TISY12455', '429302069', 2, 5, 1, 1950.00, -18.20, 0.4600, 897.00, 0, 870.00, 750.00, 120.00, 'COCIDO'),
(6, '2025-10-10 16:10:00', 'viernes', 'LG5-281', 'TISY12455', '493114101', 1, 4, 1, 3400.00, -19.00, 0.4600, 1564.00, 0, 1500.00, 1250.00, 250.00, 'COCIDO'),
(7, '2025-10-11 17:30:00', 'sábado', 'LG5-282', 'TISY12455', '493114102', 3, 2, 1, 3100.00, -16.00, 0.4416, 1368.96, 0, 1300.00, 1050.00, 250.00, 'COCIDO'),
(8, '2025-10-12 15:20:00', 'domingo', 'LG5-283', 'TISY12455', '493114103', 2, 3, 1, 3250.00, 5.20, 0.4232, 1375.40, 1, 1250.00, 1000.00, 250.00, 'COCIDO'),
(9, '2025-10-13 20:45:00', 'lunes', 'LG5-284', 'TISY12455', '493114104', 1, 3, 1, 4100.00, -18.00, 0.4600, 1886.00, 0, 1800.00, 1550.00, 250.00, 'COCIDO'),
(10, '2025-10-14 13:30:00', 'martes', 'LG5-287', 'TISY12455', '493114105', 1, 3, 1, 6031.00, -18.00, 0.4600, 2774.26, 0, 2519.00, 2169.00, 350.00, 'COCIDO'),
(11, '2025-10-15 15:00:00', 'miércoles', 'LG5-288', 'TISY12455', '494315105', 1, 3, 1, 3437.00, -17.50, 0.4600, 1581.02, 0, 1426.00, 1276.00, 150.00, 'COCIDO'),
(12, '2025-10-16 17:15:00', 'jueves', 'LG5-289', 'TISY12455', '494315106', 3, 3, 1, 2890.00, -16.80, 0.4600, 1329.40, 0, 1250.00, 1050.00, 200.00, 'COCIDO'),
(13, '2025-10-17 19:20:00', 'viernes', 'LG5-290', 'TISY12455', '494315107', 1, 4, 1, 3150.00, 6.00, 0.4232, 1333.08, 1, 1200.00, 1000.00, 200.00, 'COCIDO'),
(14, '2025-10-18 14:10:00', 'sábado', 'LG5-291', 'TISY12455', '494315108', 2, 4, 1, 4200.00, -18.50, 0.4600, 1932.00, 0, 1850.00, 1600.00, 250.00, 'COCIDO'),
(15, '2025-10-19 16:05:00', 'domingo', 'LG5-292', 'TISY12455', '494315109', 1, 5, 1, 5100.00, -19.20, 0.4600, 2346.00, 0, 2250.00, 1950.00, 300.00, 'COCIDO'),
(16, '2026-03-15 18:42:50', NULL, '290', 'ND', '2020', 2, 3, 1, 2112.00, 34.00, 0.4232, 893.80, 1, NULL, NULL, NULL, 'COCIDO'),
(18, '2026-03-15 18:43:06', NULL, '2903', 'ND', '2020', 2, 3, 1, 2112.00, -4.00, 0.4600, 971.52, 0, NULL, NULL, NULL, 'COCIDO'),
(19, '2026-03-15 18:54:34', NULL, '290', 'ND', '4343', 1, 3, 1, 90.00, 89.00, 0.4232, 38.09, 1, NULL, NULL, NULL, 'COCIDO');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `proveedores`
--

CREATE TABLE `proveedores` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `nivel_riesgo` enum('bajo','medio','alto') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `proveedores`
--

INSERT INTO `proveedores` (`id`, `nombre`, `nivel_riesgo`) VALUES
(1, 'SANT YAGO UNO', 'bajo'),
(2, 'Pesquera del Pacífico', 'medio'),
(3, 'Proveedores Locales', 'alto');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tallas`
--

CREATE TABLE `tallas` (
  `id` int(11) NOT NULL,
  `rango` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `tallas`
--

INSERT INTO `tallas` (`id`, `rango`) VALUES
(1, '-1.4'),
(2, '1.4-1.8'),
(3, '1.8-3.4'),
(4, '3.4-10'),
(5, '10.00-20.00'),
(6, '20.00-UP');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `especies`
--
ALTER TABLE `especies`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `predicciones`
--
ALTER TABLE `predicciones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lote_partida_unique` (`lote`,`partida`),
  ADD KEY `proveedor_id` (`proveedor_id`),
  ADD KEY `especie_id` (`especie_id`),
  ADD KEY `talla_id` (`talla_id`),
  ADD KEY `idx_predicciones_compra` (`compra`,`talla_id`),
  ADD KEY `idx_predicciones_fecha` (`fecha_registro`);

--
-- Indices de la tabla `proveedores`
--
ALTER TABLE `proveedores`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `tallas`
--
ALTER TABLE `tallas`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `especies`
--
ALTER TABLE `especies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `predicciones`
--
ALTER TABLE `predicciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT de la tabla `proveedores`
--
ALTER TABLE `proveedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `tallas`
--
ALTER TABLE `tallas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `predicciones`
--
ALTER TABLE `predicciones`
  ADD CONSTRAINT `predicciones_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `predicciones_ibfk_2` FOREIGN KEY (`especie_id`) REFERENCES `especies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `predicciones_ibfk_3` FOREIGN KEY (`talla_id`) REFERENCES `tallas` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
