<?php

declare(strict_types=1);

/**
 * MBSLVModbusServer
 *
 * Reiner Modbus-TCP-Server-Kern (MBAP-Header + PDU) ohne IPS-Abhängigkeiten,
 * dadurch mit der PHP-CLI testbar (siehe tests/).
 *
 * Unterstützte Function Codes:
 *   FC 03 Read Holding Registers
 *   FC 04 Read Input Registers
 *   FC 06 Write Single Register
 *   FC 16 Write Multiple Registers
 *
 * Registerzeilen (Array):
 *   Area      0 = Holding, 1 = Input
 *   Address   0-basierte Protokolladresse des ersten Words
 *   DataType  uint16 | int16 | uint32 | int32 | float32 | float64
 *   Writable  true = per FC 06/16 beschreibbar
 *   (weitere Schlüssel wie VariableID/Factor/Fixed/Ident werden unverändert an
 *    die Reader-/Writer-Callbacks durchgereicht)
 *
 * Word-Order: Modbus überträgt jedes 16-Bit-Register Big-Endian. Bei
 * Mehrwort-Werten bestimmt $swapWords die Reihenfolge der Words:
 *   false = High-Word zuerst (Big-Endian, "ABCD")
 *   true  = Low-Word zuerst  (Little-Endian word order, "CDAB",
 *           entspricht Meteocontrol blue'Log RPC: 0xCCDDAABB)
 */
class MBSLVModbusServer
{
    public const AREA_HOLDING = 0;
    public const AREA_INPUT = 1;

    public const UNMAPPED_ZERO = 0;
    public const UNMAPPED_EXCEPTION = 1;

    private const EXC_ILLEGAL_FUNCTION = 0x01;
    private const EXC_ILLEGAL_ADDRESS = 0x02;
    private const EXC_ILLEGAL_VALUE = 0x03;
    private const EXC_GATEWAY_TARGET = 0x0B;

    private array $rows;
    private array $index = [self::AREA_HOLDING => [], self::AREA_INPUT => []];
    private bool $swapWords;
    private int $unitID;
    private bool $checkUnitID;
    private int $unmappedMode;
    /** @var callable(array): float liefert den Registerwert (bereits skaliert) */
    private $reader;
    /** @var callable(array, float): void nimmt einen geschriebenen Wert entgegen */
    private $writer;
    /** @var callable(string, string): void */
    private $logger;

    public function __construct(
        array $rows,
        bool $swapWords,
        int $unitID,
        bool $checkUnitID,
        int $unmappedMode,
        callable $reader,
        callable $writer,
        ?callable $logger = null
    ) {
        $this->rows = array_values($rows);
        $this->swapWords = $swapWords;
        $this->unitID = $unitID;
        $this->checkUnitID = $checkUnitID;
        $this->unmappedMode = $unmappedMode;
        $this->reader = $reader;
        $this->writer = $writer;
        $this->logger = $logger ?? function (string $topic, string $message) {};

        foreach ($this->rows as $ri => $row) {
            $area = (int) ($row['Area'] ?? self::AREA_HOLDING);
            $base = (int) ($row['Address'] ?? 0);
            $words = self::wordCount((string) ($row['DataType'] ?? 'uint16'));
            for ($w = 0; $w < $words; $w++) {
                $addr = $base + $w;
                if (isset($this->index[$area][$addr])) {
                    ($this->logger)('Konfiguration', sprintf('Register %d ist mehrfach belegt - erste Definition gewinnt', $addr));
                    continue;
                }
                $this->index[$area][$addr] = [$ri, $w];
            }
        }
    }

    public static function wordCount(string $dataType): int
    {
        switch ($dataType) {
            case 'float64':
                return 4;
            case 'uint32':
            case 'int32':
            case 'float32':
                return 2;
            default:
                return 1;
        }
    }

    /**
     * Zerlegt den Empfangspuffer in vollständige MBAP-Frames.
     *
     * @return array{0: string[], 1: string} [Frames, Restpuffer]
     */
    public static function extractFrames(string $buffer): array
    {
        $frames = [];
        while (strlen($buffer) >= 7) {
            $header = unpack('ntid/nproto/nlen', substr($buffer, 0, 6));
            if ($header['proto'] !== 0 || $header['len'] < 2 || $header['len'] > 254) {
                // Kein gültiger MBAP-Header: Resynchronisation ist nicht möglich, Puffer verwerfen
                return [$frames, ''];
            }
            $total = 6 + $header['len'];
            if (strlen($buffer) < $total) {
                break;
            }
            $frames[] = substr($buffer, 0, $total);
            $buffer = substr($buffer, $total);
        }
        return [$frames, $buffer];
    }

    /**
     * Verarbeitet einen vollständigen MBAP-Frame und liefert den Antwort-Frame
     * (oder null, wenn keine Antwort gesendet werden soll).
     */
    public function process(string $frame): ?string
    {
        if (strlen($frame) < 8) {
            return null;
        }
        $h = unpack('ntid/nproto/nlen/Cunit/Cfc', substr($frame, 0, 8));
        $tid = $h['tid'];
        $unit = $h['unit'];
        $fc = $h['fc'];
        $data = substr($frame, 8);

        if ($this->checkUnitID && $unit !== $this->unitID) {
            ($this->logger)('Anfrage', sprintf('Unit-ID %d passt nicht (erwartet %d)', $unit, $this->unitID));
            return $this->exception($tid, $unit, $fc, self::EXC_GATEWAY_TARGET);
        }

        switch ($fc) {
            case 3:
            case 4:
                return $this->readRegisters($tid, $unit, $fc, $data);
            case 6:
                return $this->writeSingle($tid, $unit, $data);
            case 16:
                return $this->writeMultiple($tid, $unit, $data);
            default:
                ($this->logger)('Anfrage', sprintf('Function Code %d nicht unterstützt', $fc));
                return $this->exception($tid, $unit, $fc, self::EXC_ILLEGAL_FUNCTION);
        }
    }

    private function readRegisters(int $tid, int $unit, int $fc, string $data): string
    {
        if (strlen($data) < 4) {
            return $this->exception($tid, $unit, $fc, self::EXC_ILLEGAL_VALUE);
        }
        $p = unpack('nstart/ncount', $data);
        if ($p['count'] < 1 || $p['count'] > 125) {
            return $this->exception($tid, $unit, $fc, self::EXC_ILLEGAL_VALUE);
        }
        $index = $this->index[$fc === 3 ? self::AREA_HOLDING : self::AREA_INPUT];
        $cache = [];
        $words = [];
        for ($i = 0; $i < $p['count']; $i++) {
            $addr = $p['start'] + $i;
            if (!isset($index[$addr])) {
                if ($this->unmappedMode === self::UNMAPPED_EXCEPTION) {
                    return $this->exception($tid, $unit, $fc, self::EXC_ILLEGAL_ADDRESS);
                }
                $words[] = 0;
                continue;
            }
            [$ri, $wi] = $index[$addr];
            if (!isset($cache[$ri])) {
                $row = $this->rows[$ri];
                $value = (float) ($this->reader)($row);
                $cache[$ri] = self::valueToWords($value, (string) $row['DataType'], $this->swapWords);
            }
            $words[] = $cache[$ri][$wi];
        }
        $pdu = chr($fc) . chr($p['count'] * 2) . pack('n*', ...$words);
        return $this->reply($tid, $unit, $pdu);
    }

    private function writeSingle(int $tid, int $unit, string $data): string
    {
        if (strlen($data) < 4) {
            return $this->exception($tid, $unit, 6, self::EXC_ILLEGAL_VALUE);
        }
        $p = unpack('naddr/nvalue', $data);
        $this->applyWrites($p['addr'], [$p['value']]);
        return $this->reply($tid, $unit, chr(6) . pack('nn', $p['addr'], $p['value']));
    }

    private function writeMultiple(int $tid, int $unit, string $data): string
    {
        if (strlen($data) < 5) {
            return $this->exception($tid, $unit, 16, self::EXC_ILLEGAL_VALUE);
        }
        $p = unpack('nstart/ncount/Cbytes', substr($data, 0, 5));
        if ($p['count'] < 1 || $p['count'] > 123 || $p['bytes'] !== $p['count'] * 2 || strlen($data) < 5 + $p['bytes']) {
            return $this->exception($tid, $unit, 16, self::EXC_ILLEGAL_VALUE);
        }
        $words = array_values(unpack('n*', substr($data, 5, $p['bytes'])));
        $this->applyWrites($p['start'], $words);
        return $this->reply($tid, $unit, chr(16) . pack('nn', $p['start'], $p['count']));
    }

    /**
     * Wendet geschriebene Words auf die Registertabelle an. Es werden nur Werte
     * übernommen, deren Words vollständig im Schreibbereich liegen; unbelegte
     * oder nur teilweise überdeckte Register werden toleriert und ignoriert.
     */
    private function applyWrites(int $start, array $words): void
    {
        $count = count($words);
        $done = [];
        for ($i = 0; $i < $count; $i++) {
            $addr = $start + $i;
            if (!isset($this->index[self::AREA_HOLDING][$addr])) {
                continue;
            }
            [$ri, ] = $this->index[self::AREA_HOLDING][$addr];
            if (isset($done[$ri])) {
                continue;
            }
            $done[$ri] = true;
            $row = $this->rows[$ri];
            if (empty($row['Writable'])) {
                ($this->logger)('Schreiben', sprintf('Register %d ist nicht beschreibbar - Wert verworfen', $addr));
                continue;
            }
            $base = (int) $row['Address'];
            $n = self::wordCount((string) $row['DataType']);
            if ($base < $start || $base + $n > $start + $count) {
                ($this->logger)('Schreiben', sprintf('Register %d nur teilweise beschrieben - Wert verworfen', $base));
                continue;
            }
            $value = self::wordsToValue(array_slice($words, $base - $start, $n), (string) $row['DataType'], $this->swapWords);
            ($this->writer)($row, $value);
        }
    }

    /** @return int[] 16-Bit-Words in Sendereihenfolge */
    public static function valueToWords(float $value, string $dataType, bool $swapWords): array
    {
        switch ($dataType) {
            case 'float32':
                $bin = pack('G', $value);
                break;
            case 'float64':
                $bin = pack('E', $value);
                break;
            case 'uint32':
            case 'int32':
                $bin = pack('N', ((int) round($value)) & 0xFFFFFFFF);
                break;
            default:
                $bin = pack('n', ((int) round($value)) & 0xFFFF);
        }
        $words = array_values(unpack('n*', $bin));
        if ($swapWords && count($words) > 1) {
            $words = array_reverse($words);
        }
        return $words;
    }

    /** @param int[] $words 16-Bit-Words in Empfangsreihenfolge */
    public static function wordsToValue(array $words, string $dataType, bool $swapWords): float
    {
        if ($swapWords && count($words) > 1) {
            $words = array_reverse($words);
        }
        $bin = pack('n*', ...$words);
        switch ($dataType) {
            case 'float32':
                return (float) unpack('G', $bin)[1];
            case 'float64':
                return (float) unpack('E', $bin)[1];
            case 'int16':
                $v = unpack('n', $bin)[1];
                return (float) ($v >= 0x8000 ? $v - 0x10000 : $v);
            case 'uint32':
                return (float) unpack('N', $bin)[1];
            case 'int32':
                $v = unpack('N', $bin)[1];
                return (float) ($v >= 0x80000000 ? $v - 0x100000000 : $v);
            default:
                return (float) unpack('n', $bin)[1];
        }
    }

    private function reply(int $tid, int $unit, string $pdu): string
    {
        return pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($unit) . $pdu;
    }

    private function exception(int $tid, int $unit, int $fc, int $code): string
    {
        return $this->reply($tid, $unit, chr(($fc | 0x80) & 0xFF) . chr($code));
    }
}
