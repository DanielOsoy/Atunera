<?php

/**
 * Modelo estadístico de la planta, calibrado con el historial real (2024-2026).
 *
 * Produce compras y partidas con el mismo comportamiento que los datos reales:
 *   - Qué barcos entregan más y de qué tamaño son sus compras.
 *   - Una especie dominante por compra (87% Yellowfin en total).
 *   - Talla condicionada a la especie.
 *   - Rendimiento con la media y desviación real de cada especie+talla,
 *     más un efecto propio de cada barco y una leve estacionalidad.
 */
final class ModeloPlanta
{
    // Abreviatura => [compras, partidas] observadas en el historial real
    private const BARCOS_REALES = [
        'BAR' => [7, 330], 'ETU' => [6, 220], 'TEM' => [6, 209], 'GRO' => [4, 169],
        'PPP' => [54, 131], 'ALB' => [4, 104], 'LJU' => [2, 103], 'SY1' => [13, 96],
        'AL1' => [3, 95], 'LPE' => [3, 95], 'CCR' => [3, 92], 'PST' => [8, 83],
        'LRO' => [3, 81], 'CQT' => [11, 73], 'CNO' => [3, 67], 'AIC' => [8, 48],
        'DMR' => [2, 47], 'AB2' => [3, 34], 'AL4' => [2, 33], 'LAU' => [1, 25],
        'SEA' => [3, 22], 'GLA' => [1, 20], 'GA3' => [2, 19], 'NAI' => [3, 18],
        'VIT' => [5, 18], 'RCR' => [2, 17], 'PRY' => [3, 15], 'BLD' => [3, 14],
        'SS3' => [2, 14], 'UPA' => [2, 14], 'SE3' => [1, 13], 'DRA' => [3, 12],
        'SS0' => [1, 12], 'OKI' => [2, 8], 'VMI' => [1, 8], 'SOP' => [2, 8],
        'GA2' => [1, 7], 'JAS' => [1, 6], 'XIX' => [2, 5], 'PS1' => [1, 4],
        'ALJ' => [1, 3], 'MDR' => [1, 3], 'JMI' => [1, 2], 'A10' => [1, 1],
    ];

    // Barcos que no aparecen en el historial: entregan poco y en compras chicas
    private const BARCO_SIN_HISTORIAL = [0.5, 5];

    private const PESO_ESPECIE_PRINCIPAL = [1 => 85, 3 => 10, 2 => 5];
    private const PROB_MISMA_ESPECIE     = 0.85;

    // [especie][talla] => [partidas, media, desviación] del historial real
    private const RENDIMIENTO_REAL = [
        1 => [1 => [38, .4202, .0286], 2 => [58, .4223, .0252], 3 => [130, .4301, .0302],
              4 => [378, .4401, .0207], 5 => [471, .4699, .0194], 6 => [297, .4784, .0225],
              7 => [368, .4839, .0144], 8 => [253, .4958, .0230], 9 => [23, .5311, .0310],
              10 => [5, .5368, .0182], 11 => [1, .4870, 0], 13 => [5, .4235, .0080],
              14 => [5, .4351, .0095], 15 => [14, .4372, .0118], 16 => [11, .4484, .0072],
              17 => [26, .4543, .0180]],
        2 => [1 => [4, .4257, .0103], 2 => [12, .4405, .0167], 3 => [18, .4472, .0158],
              4 => [23, .4460, .0331], 5 => [12, .4854, .0316], 6 => [13, .4879, .0387],
              7 => [4, .4889, .0094], 8 => [4, .4915, .0046], 9 => [1, .5083, 0],
              10 => [2, .4991, .0052]],
        3 => [1 => [34, .3712, .0390], 2 => [29, .3839, .0282], 3 => [68, .4129, .0347],
              4 => [87, .4344, .0348], 7 => [1, .4800, 0], 14 => [1, .5339, 0],
              17 => [2, .4532, .0062]],
    ];

    // Con pocas muestras se usa el estándar de la talla ajustado por especie
    private const MIN_MUESTRAS        = 10;
    private const DELTA_ESPECIE       = [1 => 0.0, 2 => 0.01, 3 => -0.035];
    private const DESVIACION_DEFECTO  = 0.025;
    private const AMPLITUD_ESTACIONAL = 0.006;

    private const PREFIJOS = ['TI' => 67, 'FP' => 7, 'AL' => 5, 'AB' => 5, 'PT' => 4, 'RZ' => 2,
                              'JC' => 2, 'RP' => 2, 'IH' => 2, 'RW' => 2, 'JA' => 1, 'EU' => 1];

    private const PROB_CORRECCION = 0.03;

    // La planta registra en hora local (UTC-6, sin horario de verano);
    // FechaCreacion y FechaModificacion se guardan en UTC como en SQL Server.
    private const DESFASE_UTC_HORAS = 6;

    /** @var array<int, array{abrev:string, peso:float, tamano:float, efecto:float}> */
    private array $barcos = [];
    /** @var array<int, float> */
    private array $estandares = [];
    /** @var array<string, true> */
    private array $codigosUsados = [];

    /**
     * @param array<int, array{BarcoID:int|string, Abreviatura:string}> $barcosCatalogo
     * @param array<int, array{TallaID:int|string, Estandar:float|string}> $tallasCatalogo
     */
    public function __construct(array $barcosCatalogo, array $tallasCatalogo, int $semilla)
    {
        mt_srand($semilla);

        foreach ($tallasCatalogo as $t) {
            $this->estandares[(int)$t['TallaID']] = (float)$t['Estandar'];
        }

        foreach ($barcosCatalogo as $b) {
            [$compras, $partidas] = self::BARCOS_REALES[$b['Abreviatura']] ?? self::BARCO_SIN_HISTORIAL;
            $this->barcos[(int)$b['BarcoID']] = [
                'abrev'  => $b['Abreviatura'],
                'peso'   => (float)$compras,
                'tamano' => $partidas / $compras,
                'efecto' => $this->normal(0, 0.008),
            ];
        }
    }

    public function marcarCodigoUsado(string $codigo): void
    {
        $this->codigosUsados[$codigo] = true;
    }

    /**
     * Abre una compra nueva: barco, código único, especie principal y cuántas partidas trae.
     *
     * @param callable(string):bool|null $existeEnBase  Verificación extra contra la base (modo incremental)
     */
    public function nuevaCompra(DateTimeImmutable $fecha, ?callable $existeEnBase = null): array
    {
        $barcoId = $this->elegirPonderado(array_map(fn($b) => $b['peso'], $this->barcos));
        $barco   = $this->barcos[$barcoId];

        do {
            $codigo = $this->elegirPonderado(self::PREFIJOS)
                    . $barco['abrev']
                    . strtoupper(str_pad(base_convert((string)mt_rand(0, 46655), 10, 36), 3, '0', STR_PAD_LEFT))
                    . substr($fecha->format('Y'), -1);
        } while (isset($this->codigosUsados[$codigo]) || ($existeEnBase && $existeEnBase($codigo)));
        $this->marcarCodigoUsado($codigo);

        $tamano = (int)max(1, min(150, round($barco['tamano'] * exp($this->normal(0, 0.4)))));

        return [
            'codigo'           => $codigo,
            'barcoId'          => $barcoId,
            'especiePrincipal' => $this->elegirPonderado(self::PESO_ESPECIE_PRINCIPAL),
            'restantes'        => $tamano,
        ];
    }

    /**
     * Genera una partida de la compra (descuenta una de sus partidas restantes).
     * Devuelve las columnas de dbo.Partidas.
     */
    public function nuevaPartida(array &$compra, DateTimeImmutable $momento, bool $conFechasDeRegistro = true): array
    {
        $compra['restantes']--;

        $especie = mt_rand() / mt_getrandmax() < self::PROB_MISMA_ESPECIE
            ? $compra['especiePrincipal']
            : $this->elegirPonderado(self::PESO_ESPECIE_PRINCIPAL);

        $tallasPosibles = array_intersect_key(self::RENDIMIENTO_REAL[$especie], $this->estandares);
        $talla = $this->elegirPonderado(array_map(fn($d) => $d[0], $tallasPosibles));

        $rendimiento = $this->rendimiento($especie, $talla, $compra['barcoId'], $momento);

        $fila = [
            'CodigoCompra'  => $compra['codigo'],
            'FechaProceso'  => $momento->format('Y-m-d H:i:s'),
            'BarcoID'       => $compra['barcoId'],
            'EspecieID'     => $especie,
            'TallaID'       => $talla,
            'PesoNetoKg'    => round(800 + mt_rand(0, 520000) / 100, 2),
            'Rendimiento'   => $rendimiento,
            'Turno'         => (int)$momento->format('G') < 14 ? 'A' : 'B',
            'Operador'      => sprintf('Operario %03d', mt_rand(1, 40)),
            'Observaciones' => null,
        ];

        if ($conFechasDeRegistro) {
            $creacion = $momento->modify(sprintf('+%d hours +%d minutes', self::DESFASE_UTC_HORAS, mt_rand(5, 180)));
            $modificacion = $creacion;

            // Una pequeña parte se corrige días después (control de calidad)
            if (mt_rand() / mt_getrandmax() < self::PROB_CORRECCION) {
                $modificacion = $creacion->modify(sprintf('+%d days +%d minutes', mt_rand(1, 10), mt_rand(0, 600)));
                $fila['Rendimiento']   = $this->ajusteCorreccion($rendimiento);
                $fila['Observaciones'] = 'Rendimiento corregido por control de calidad';
            }

            $fila['FechaCreacion']     = $creacion->format('Y-m-d H:i:s.v');
            $fila['FechaModificacion'] = $modificacion->format('Y-m-d H:i:s.v');
        }

        return $fila;
    }

    public function ajusteCorreccion(float $rendimiento): float
    {
        return round(max(0.30, min(0.60, $rendimiento + $this->normal(0, 0.004))), 4);
    }

    /** Horas de proceso del día, ordenadas, entre 06:00 y 21:59. */
    public function horariosDelDia(DateTimeImmutable $dia, int $cantidad): array
    {
        $segundos = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $segundos[] = mt_rand(6 * 3600, 22 * 3600 - 1);
        }
        sort($segundos);
        return array_map(fn($s) => $dia->setTime(0, 0)->modify("+$s seconds"), $segundos);
    }

    public function enteroEntre(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    private function rendimiento(int $especie, int $talla, int $barcoId, DateTimeImmutable $momento): float
    {
        [$n, $media, $desviacion] = self::RENDIMIENTO_REAL[$especie][$talla];

        if ($n < self::MIN_MUESTRAS) {
            $media      = $this->estandares[$talla] + self::DELTA_ESPECIE[$especie];
            $desviacion = self::DESVIACION_DEFECTO;
        }

        $estacional = self::AMPLITUD_ESTACIONAL * sin(2 * M_PI * (int)$momento->format('z') / 365.25);
        $valor = $this->normal($media, max($desviacion, 0.01)) + $this->barcos[$barcoId]['efecto'] + $estacional;

        return round(max(0.30, min(0.60, $valor)), 4);
    }

    /** @param array<int|string, float|int> $pesos */
    private function elegirPonderado(array $pesos): int|string
    {
        $r = mt_rand() / mt_getrandmax() * array_sum($pesos);
        foreach ($pesos as $clave => $peso) {
            $r -= $peso;
            if ($r <= 0) {
                return $clave;
            }
        }
        return array_key_last($pesos);
    }

    private function normal(float $media, float $desviacion): float
    {
        $u1 = max(mt_rand() / mt_getrandmax(), 1e-12);
        $u2 = mt_rand() / mt_getrandmax();
        return $media + $desviacion * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }
}
