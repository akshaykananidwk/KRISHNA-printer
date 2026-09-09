<?php
declare(strict_types=1);

namespace App\Support;

/**
 * QR Code encoder (ISO/IEC 18004), byte mode, versions 1–20.
 *
 * Written from the specification rather than pulled in as a dependency: the
 * application must install by upload onto shared hosting with no composer step,
 * and QR generation is on the critical path for every location.
 *
 * Implements: data encoding, Reed–Solomon error correction over GF(256),
 * block interleaving, the full function-pattern layout, all eight data masks
 * with the specified penalty scoring, and BCH-encoded format/version
 * information.
 */
final class QrEncoder
{
    public const ECC_L = 0;
    public const ECC_M = 1;
    public const ECC_Q = 2;
    public const ECC_H = 3;

    /**
     * Total data codewords per (version, ecc level).
     * Index: [version - 1][ecc level]
     */
    private const DATA_CODEWORDS = [
        [19, 16, 13, 9], [34, 28, 22, 16], [55, 44, 34, 26], [80, 64, 48, 36],
        [108, 86, 62, 46], [136, 108, 76, 60], [156, 124, 88, 66], [194, 154, 110, 86],
        [232, 182, 132, 100], [274, 216, 154, 122], [324, 254, 180, 140], [370, 290, 206, 158],
        [428, 334, 244, 180], [461, 365, 261, 197], [523, 415, 295, 223], [589, 453, 325, 253],
        [647, 507, 367, 283], [721, 563, 397, 313], [795, 627, 445, 341], [861, 669, 485, 385],
    ];

    /** ECC codewords per block, indexed [version - 1][ecc level]. */
    private const ECC_PER_BLOCK = [
        [7, 10, 13, 17], [10, 16, 22, 28], [15, 26, 18, 22], [20, 18, 26, 16],
        [26, 24, 18, 22], [18, 16, 24, 28], [20, 18, 18, 26], [24, 22, 22, 26],
        [30, 22, 20, 24], [18, 26, 24, 28], [20, 30, 28, 24], [24, 22, 26, 28],
        [26, 22, 24, 22], [30, 24, 20, 24], [22, 24, 30, 24], [24, 28, 24, 30],
        [28, 28, 28, 28], [30, 26, 28, 28], [28, 26, 26, 26], [28, 26, 30, 28],
    ];

    /** Number of error-correction blocks, indexed [version - 1][ecc level]. */
    private const ECC_BLOCKS = [
        [1, 1, 1, 1], [1, 1, 1, 1], [1, 1, 2, 2], [1, 2, 2, 4],
        [1, 2, 4, 4], [2, 4, 4, 4], [2, 4, 6, 5], [2, 4, 6, 6],
        [2, 5, 8, 8], [4, 5, 8, 8], [4, 5, 8, 11], [4, 8, 10, 11],
        [4, 9, 12, 16], [4, 9, 16, 16], [6, 10, 12, 18], [6, 10, 17, 16],
        [6, 11, 16, 19], [6, 13, 18, 21], [7, 14, 21, 25], [8, 16, 20, 25],
    ];

    /** Alignment pattern centre coordinates by version. */
    private const ALIGNMENT_POSITIONS = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
        10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62],
        14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];

    /** @var array<int,int> GF(256) exponent table */
    private static array $expTable = [];

    /** @var array<int,int> GF(256) log table */
    private static array $logTable = [];

    private int $version;

    private int $size;

    /** @var array<int,array<int,int>> 0 = light, 1 = dark */
    private array $matrix = [];

    /** @var array<int,array<int,bool>> true where a function pattern lives */
    private array $reserved = [];

    public function __construct(
        private string $data,
        private int $eccLevel = self::ECC_M
    ) {
        if ($this->data === '') {
            throw new \InvalidArgumentException('Cannot encode an empty string as a QR code.');
        }
        self::initialiseGaloisField();
        $this->version = $this->selectVersion();
        $this->size = 17 + 4 * $this->version;
        $this->build();
    }

    /** @return array<int,array<int,int>> the finished module matrix */
    public function matrix(): array
    {
        return $this->matrix;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function version(): int
    {
        return $this->version;
    }

    // -----------------------------------------------------------------
    // Galois field arithmetic (GF(256), primitive polynomial 0x11D)
    // -----------------------------------------------------------------

    private static function initialiseGaloisField(): void
    {
        if (self::$expTable !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 256; $i++) {
            self::$expTable[$i] = $x;
            self::$logTable[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 256; $i < 512; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 255];
        }
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    // -----------------------------------------------------------------
    // Encoding
    // -----------------------------------------------------------------

    private function selectVersion(): int
    {
        $length = strlen($this->data);

        for ($version = 1; $version <= 20; $version++) {
            $capacityBits = self::DATA_CODEWORDS[$version - 1][$this->eccLevel] * 8;
            // 4 mode bits + character-count bits + 8 bits per byte
            $countBits = $version <= 9 ? 8 : 16;
            $requiredBits = 4 + $countBits + $length * 8;

            if ($requiredBits <= $capacityBits) {
                return $version;
            }
        }

        throw new \InvalidArgumentException(
            'The payload is too long for a version-20 QR code (' . $length . ' bytes).'
        );
    }

    /** @return array<int,int> the data codewords, padded to capacity */
    private function encodeData(): array
    {
        $bits = '';
        $bits .= '0100'; // byte mode

        $countBits = $this->version <= 9 ? 8 : 16;
        $bits .= str_pad(decbin(strlen($this->data)), $countBits, '0', STR_PAD_LEFT);

        foreach (str_split($this->data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = self::DATA_CODEWORDS[$this->version - 1][$this->eccLevel] * 8;

        // Terminator: up to four zero bits.
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));

        // Pad to a byte boundary.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $codewords = [];
        foreach (str_split($bits, 8) as $byte) {
            $codewords[] = bindec($byte);
        }

        // Pad with the specified alternating bytes.
        $padBytes = [0xEC, 0x11];
        $index = 0;
        $capacity = self::DATA_CODEWORDS[$this->version - 1][$this->eccLevel];
        while (count($codewords) < $capacity) {
            $codewords[] = $padBytes[$index % 2];
            $index++;
        }

        return $codewords;
    }

    /**
     * Split into blocks, compute Reed–Solomon codewords for each, then
     * interleave data and ECC as the specification requires.
     *
     * @param array<int,int> $data
     * @return array<int,int>
     */
    private function addErrorCorrection(array $data): array
    {
        $totalBlocks = self::ECC_BLOCKS[$this->version - 1][$this->eccLevel];
        $eccPerBlock = self::ECC_PER_BLOCK[$this->version - 1][$this->eccLevel];
        $totalDataCodewords = count($data);

        // Blocks come in two group sizes; the larger group holds one more
        // codeword each and sits at the end.
        $shortBlockSize = intdiv($totalDataCodewords, $totalBlocks);
        $longBlockCount = $totalDataCodewords % $totalBlocks;
        $shortBlockCount = $totalBlocks - $longBlockCount;

        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        for ($i = 0; $i < $totalBlocks; $i++) {
            $blockSize = $i < $shortBlockCount ? $shortBlockSize : $shortBlockSize + 1;
            $block = array_slice($data, $offset, $blockSize);
            $offset += $blockSize;

            $dataBlocks[] = $block;
            $eccBlocks[] = $this->reedSolomon($block, $eccPerBlock);
        }

        // Interleave data codewords column-wise across blocks.
        $result = [];
        $maxDataLength = $shortBlockSize + ($longBlockCount > 0 ? 1 : 0);
        for ($i = 0; $i < $maxDataLength; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        // Then interleave the ECC codewords the same way.
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Reed–Solomon remainder: polynomial division of the message by the
     * generator polynomial over GF(256).
     *
     * @param array<int,int> $block
     * @return array<int,int>
     */
    private function reedSolomon(array $block, int $eccCount): array
    {
        $generator = $this->generatorPolynomial($eccCount);
        $remainder = array_merge($block, array_fill(0, $eccCount, 0));

        for ($i = 0; $i < count($block); $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            for ($j = 0; $j <= $eccCount; $j++) {
                $remainder[$i + $j] ^= self::gfMultiply($generator[$j], $factor);
            }
        }

        return array_slice($remainder, count($block), $eccCount);
    }

    /** @return array<int,int> */
    private function generatorPolynomial(int $degree): array
    {
        $polynomial = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($polynomial) + 1, 0);
            for ($j = 0; $j < count($polynomial); $j++) {
                $next[$j] ^= $polynomial[$j];
                $next[$j + 1] ^= self::gfMultiply($polynomial[$j], self::$expTable[$i]);
            }
            $polynomial = $next;
        }
        return $polynomial;
    }

    // -----------------------------------------------------------------
    // Matrix construction
    // -----------------------------------------------------------------

    private function build(): void
    {
        $this->matrix = array_fill(0, $this->size, array_fill(0, $this->size, 0));
        $this->reserved = array_fill(0, $this->size, array_fill(0, $this->size, false));

        $this->placeFinderPatterns();
        $this->placeSeparators();
        $this->placeAlignmentPatterns();
        $this->placeTimingPatterns();
        $this->placeDarkModuleAndReserveFormat();

        if ($this->version >= 7) {
            $this->reserveVersionInformation();
        }

        $codewords = $this->addErrorCorrection($this->encodeData());
        $this->placeData($codewords);

        $mask = $this->selectBestMask();
        $this->applyMask($mask);
        $this->placeFormatInformation($mask);

        if ($this->version >= 7) {
            $this->placeVersionInformation();
        }
    }

    private function placeFinderPatterns(): void
    {
        $positions = [[0, 0], [$this->size - 7, 0], [0, $this->size - 7]];

        foreach ($positions as [$startX, $startY]) {
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    // Concentric rings: 7x7 dark border, light ring, 3x3 dark core.
                    $isDark = ($x === 0 || $x === 6 || $y === 0 || $y === 6)
                        || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                    $this->setFunctionModule($startX + $x, $startY + $y, $isDark ? 1 : 0);
                }
            }
        }
    }

    private function placeSeparators(): void
    {
        // A one-module light border around each finder pattern.
        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($i, 7, 0);
            $this->setFunctionModule(7, $i, 0);

            $this->setFunctionModule($this->size - 1 - $i, 7, 0);
            $this->setFunctionModule($this->size - 8, $i, 0);

            $this->setFunctionModule($i, $this->size - 8, 0);
            $this->setFunctionModule(7, $this->size - 1 - $i, 0);
        }
    }

    private function placeAlignmentPatterns(): void
    {
        $positions = self::ALIGNMENT_POSITIONS[$this->version] ?? [];
        $count = count($positions);

        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                // The three corners already hold finder patterns.
                if (($i === 0 && $j === 0)
                    || ($i === 0 && $j === $count - 1)
                    || ($i === $count - 1 && $j === 0)) {
                    continue;
                }

                $centreX = $positions[$j];
                $centreY = $positions[$i];

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $isDark = max(abs($dx), abs($dy)) !== 1;
                        $this->setFunctionModule($centreX + $dx, $centreY + $dy, $isDark ? 1 : 0);
                    }
                }
            }
        }
    }

    private function placeTimingPatterns(): void
    {
        for ($i = 8; $i < $this->size - 8; $i++) {
            $value = $i % 2 === 0 ? 1 : 0;
            $this->setFunctionModule($i, 6, $value);
            $this->setFunctionModule(6, $i, $value);
        }
    }

    private function placeDarkModuleAndReserveFormat(): void
    {
        // The always-dark module.
        $this->setFunctionModule(8, $this->size - 8, 1);

        // Reserve the format-information areas; the bits are written after masking.
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $this->reserve(8, $i);
                $this->reserve($i, 8);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $this->reserve($this->size - 1 - $i, 8);
            $this->reserve(8, $this->size - 1 - $i);
        }
    }

    private function reserveVersionInformation(): void
    {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $this->reserve($this->size - 11 + $j, $i);
                $this->reserve($i, $this->size - 11 + $j);
            }
        }
    }

    /** @param array<int,int> $codewords */
    private function placeData(array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $bitIndex = 0;
        $bitCount = strlen($bits);
        $upward = true;

        // Two-module-wide columns, right to left, alternating direction and
        // skipping the vertical timing pattern column.
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                $y = $upward ? $this->size - 1 - $vertical : $vertical;

                for ($column = 0; $column < 2; $column++) {
                    $x = $right - $column;

                    if ($this->reserved[$y][$x]) {
                        continue;
                    }

                    $bit = $bitIndex < $bitCount ? (int) $bits[$bitIndex] : 0;
                    $this->matrix[$y][$x] = $bit;
                    $bitIndex++;
                }
            }

            $upward = !$upward;
        }
    }

    private function maskCondition(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
            6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            7 => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            default => false,
        };
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->reserved[$y][$x]) {
                    continue;
                }
                if ($this->maskCondition($mask, $x, $y)) {
                    $this->matrix[$y][$x] ^= 1;
                }
            }
        }
    }

    /** Try all eight masks and keep the one with the lowest penalty score. */
    private function selectBestMask(): int
    {
        $best = 0;
        $bestScore = PHP_INT_MAX;
        $original = $this->matrix;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->matrix = $original;
            $this->applyMask($mask);
            $this->placeFormatInformation($mask);

            $score = $this->penaltyScore();
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $mask;
            }
        }

        $this->matrix = $original;
        return $best;
    }

    /** The four penalty rules from ISO/IEC 18004 §8.8.2. */
    private function penaltyScore(): int
    {
        $score = 0;
        $size = $this->size;

        // Rule 1: runs of five or more same-coloured modules in a line.
        for ($y = 0; $y < $size; $y++) {
            $runColour = -1;
            $runLength = 0;
            for ($x = 0; $x < $size; $x++) {
                if ($this->matrix[$y][$x] === $runColour) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $score += 3 + ($runLength - 5);
                    }
                    $runColour = $this->matrix[$y][$x];
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $score += 3 + ($runLength - 5);
            }
        }
        for ($x = 0; $x < $size; $x++) {
            $runColour = -1;
            $runLength = 0;
            for ($y = 0; $y < $size; $y++) {
                if ($this->matrix[$y][$x] === $runColour) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $score += 3 + ($runLength - 5);
                    }
                    $runColour = $this->matrix[$y][$x];
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $score += 3 + ($runLength - 5);
            }
        }

        // Rule 2: 2x2 blocks of one colour.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $value = $this->matrix[$y][$x];
                if ($value === $this->matrix[$y][$x + 1]
                    && $value === $this->matrix[$y + 1][$x]
                    && $value === $this->matrix[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 3: finder-like 1:1:3:1:1 patterns with four light modules beside them.
        $patternA = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $patternB = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x <= $size - 11; $x++) {
                $rowSlice = array_slice($this->matrix[$y], $x, 11);
                if ($rowSlice === $patternA || $rowSlice === $patternB) {
                    $score += 40;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y <= $size - 11; $y++) {
                $columnSlice = [];
                for ($i = 0; $i < 11; $i++) {
                    $columnSlice[] = $this->matrix[$y + $i][$x];
                }
                if ($columnSlice === $patternA || $columnSlice === $patternB) {
                    $score += 40;
                }
            }
        }

        // Rule 4: deviation from a 50% dark ratio.
        $dark = 0;
        foreach ($this->matrix as $row) {
            $dark += array_sum($row);
        }
        $total = $size * $size;
        $percent = ($dark * 100) / $total;
        $score += ((int) (abs($percent - 50) / 5)) * 10;

        return $score;
    }

    /**
     * Format information: 5 bits (ecc level + mask) protected by a BCH(15,5)
     * code, then XORed with the fixed mask 0x5412.
     */
    private function placeFormatInformation(int $mask): void
    {
        // The bit order for the ECC level indicator is L=01, M=00, Q=11, H=10.
        $eccBits = [self::ECC_L => 1, self::ECC_M => 0, self::ECC_Q => 3, self::ECC_H => 2][$this->eccLevel];
        $data = ($eccBits << 3) | $mask;

        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;

        // Copy 1, around the top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $this->matrix[$i][8] = ($bits >> $i) & 1;
        }
        $this->matrix[7][8] = ($bits >> 6) & 1;
        $this->matrix[8][8] = ($bits >> 7) & 1;
        $this->matrix[8][7] = ($bits >> 8) & 1;
        for ($i = 9; $i <= 14; $i++) {
            $this->matrix[8][14 - $i] = ($bits >> $i) & 1;
        }

        // Copy 2, split across the other two finders.
        for ($i = 0; $i <= 7; $i++) {
            $this->matrix[8][$this->size - 1 - $i] = ($bits >> $i) & 1;
        }
        for ($i = 8; $i <= 14; $i++) {
            $this->matrix[$this->size - 15 + $i][8] = ($bits >> $i) & 1;
        }
    }

    /**
     * Version information (version 7 and above): 6 data bits protected by a
     * BCH(18,6) code.
     */
    private function placeVersionInformation(): void
    {
        $remainder = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 11) & 1) * 0x1F25);
        }
        $bits = ($this->version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;
            $x = intdiv($i, 3);
            $y = $this->size - 11 + ($i % 3);
            $this->matrix[$y][$x] = $bit;
            $this->matrix[$x][$y] = $bit;
        }
    }

    private function setFunctionModule(int $x, int $y, int $value): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }
        $this->matrix[$y][$x] = $value;
        $this->reserved[$y][$x] = true;
    }

    private function reserve(int $x, int $y): void
    {
        if ($x >= 0 && $y >= 0 && $x < $this->size && $y < $this->size) {
            $this->reserved[$y][$x] = true;
        }
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    /** Render as an SVG string — resolution-independent, ideal for printing. */
    public function toSvg(int $moduleSize = 8, int $quietZone = 4, string $dark = '#000000', string $light = '#FFFFFF'): string
    {
        $dimension = ($this->size + $quietZone * 2) * $moduleSize;

        $paths = [];
        for ($y = 0; $y < $this->size; $y++) {
            // Merge horizontal runs into single rects: a version-5 code drops
            // from ~600 elements to ~150, which matters when a page shows
            // fifty of them.
            $runStart = null;
            for ($x = 0; $x <= $this->size; $x++) {
                $isDark = $x < $this->size && $this->matrix[$y][$x] === 1;
                if ($isDark && $runStart === null) {
                    $runStart = $x;
                } elseif (!$isDark && $runStart !== null) {
                    $paths[] = sprintf(
                        '<rect x="%d" y="%d" width="%d" height="%d"/>',
                        ($runStart + $quietZone) * $moduleSize,
                        ($y + $quietZone) * $moduleSize,
                        ($x - $runStart) * $moduleSize,
                        $moduleSize
                    );
                    $runStart = null;
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" '
            . 'shape-rendering="crispEdges" role="img" aria-label="QR code">'
            . '<rect width="%d" height="%d" fill="%s"/><g fill="%s">%s</g></svg>',
            $dimension,
            $dimension,
            $dimension,
            $dimension,
            $dimension,
            $dimension,
            htmlspecialchars($light, ENT_QUOTES),
            htmlspecialchars($dark, ENT_QUOTES),
            implode('', $paths)
        );
    }

    /** Render as PNG bytes using GD. Returns null when GD is unavailable. */
    public function toPng(int $moduleSize = 8, int $quietZone = 4): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $dimension = ($this->size + $quietZone * 2) * $moduleSize;
        $image = imagecreatetruecolor($dimension, $dimension);

        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);

        imagefilledrectangle($image, 0, 0, $dimension - 1, $dimension - 1, $white);

        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->matrix[$y][$x] === 1) {
                    imagefilledrectangle(
                        $image,
                        ($x + $quietZone) * $moduleSize,
                        ($y + $quietZone) * $moduleSize,
                        ($x + $quietZone + 1) * $moduleSize - 1,
                        ($y + $quietZone + 1) * $moduleSize - 1,
                        $black
                    );
                }
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /** Plain-text rendering, used by the test suite to verify the matrix. */
    public function toText(string $darkChar = '██', string $lightChar = '  '): string
    {
        $lines = [];
        foreach ($this->matrix as $row) {
            $line = '';
            foreach ($row as $module) {
                $line .= $module === 1 ? $darkChar : $lightChar;
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
