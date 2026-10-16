<?php
declare(strict_types=1);

namespace AcePortal;

use RuntimeException;
use setasign\Fpdi\Fpdi;

final class CertificatePdf
{
    public static function ensureTemplate(): string
    {
        $dir = APP_ROOT . '/storage/templates';
        $template = $dir . '/acord25-template.pdf';
        $encodedPath = $template . '.b64';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the private certificate template directory.');
        }
        if (!is_file($template)) {
            if (!is_file($encodedPath) || !is_readable($encodedPath)) {
                throw new RuntimeException('The ACORD certificate template is missing from the deployment.');
            }
            $encoded = file_get_contents($encodedPath);
            $binary = is_string($encoded) ? base64_decode(preg_replace('/\s+/', '', $encoded), true) : false;
            if (!is_string($binary)) throw new RuntimeException('The stored certificate template could not be decoded.');
            if (!str_starts_with($binary, '%PDF-')) {
                $uncompressed = function_exists('gzuncompress') ? @gzuncompress($binary) : false;
                if (is_string($uncompressed)) $binary = $uncompressed;
            }
            if (!str_starts_with($binary, '%PDF-')) throw new RuntimeException('The stored certificate template is not a valid PDF.');
            if (file_put_contents($template, $binary, LOCK_EX) === false) {
                throw new RuntimeException('Could not write the certificate template. Check storage permissions.');
            }
            @chmod($template, 0640);
        }
        return $template;
    }

    public static function generate(array $policy, array $insured, array $vehicles, array $holder, array $mailConfig, string $outputPath): void
    {
        $autoload = APP_ROOT . '/vendor/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('PDF libraries are not installed. Run composer install in the project folder.');
        require_once $autoload;
        $template = self::ensureTemplate();

        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create private PDF storage.');
        }
        $addr = array_filter([
            trim((string)($insured['address_line1'] ?? '')),
            trim((string)($insured['address_line2'] ?? '')),
            trim(implode(', ', array_filter([
                trim((string)($insured['city'] ?? '')),
                trim((string)($insured['state'] ?? '')),
                trim((string)($insured['postal_code'] ?? '')),
            ], static fn(string $v): bool => $v !== ''))),
            trim((string)($insured['country'] ?? '')),
        ], static fn(string $v): bool => $v !== '');
        $insuredAddress = implode("\n", $addr);

        $vehicleLines = [];
        foreach ($vehicles as $v) {
            $details = trim(implode(' ', array_filter([
                trim((string)($v['model_year'] ?? '')),
                trim((string)($v['make'] ?? '')),
                trim((string)($v['model'] ?? '')),
            ], static fn(string $x): bool => $x !== '')));
            $line = trim((string)($v['vin'] ?? '')) ?: 'Vehicle';
            if (trim((string)($v['unit_number'] ?? '')) !== '') $line .= ' (Unit ' . trim((string)$v['unit_number']) . ')';
            if ($details !== '') $line .= ' - ' . $details;
            $vehicleLines[] = $line;
        }
        $coverage = [];
        if (!empty($policy['coverage_data'])) {
            $decoded = json_decode((string)$policy['coverage_data'], true);
            if (is_array($decoded)) $coverage = $decoded;
        }
        $agency = trim((string)($mailConfig['agency_name'] ?? $policy['broker_name'] ?? $mailConfig['from_name'] ?? ''));
        $holderText = trim((string)($holder['certificate_holder_name'] ?? ''));
        $holderAddress = trim((string)($holder['certificate_holder_address'] ?? ''));
        if ($holderAddress !== '') $holderText .= ($holderText !== '' ? "\n" : '') . $holderAddress;

        $data = [
            'certificate_date' => date('m/d/Y'),
            'producer_name' => $agency,
            'contact_name' => trim((string)($mailConfig['contact_name'] ?? '')),
            'contact_phone' => trim((string)($mailConfig['contact_phone'] ?? '')),
            'contact_email' => trim((string)($mailConfig['contact_email'] ?? $mailConfig['from_email'] ?? '')),
            'insured_name' => trim((string)($insured['legal_name'] ?? '')),
            'insured_address' => $insuredAddress,
            'insurer_name' => trim((string)($policy['carrier_name'] ?? '')),
            'naic_number' => self::findCoverageValue($coverage, ['naic_number', 'insurer_naic', 'naic']),
            'certificate_number' => trim((string)($policy['certificate_number'] ?? '')),
            'revision_number' => trim((string)($policy['revision_number'] ?? '')),
            'policy_number' => trim((string)($policy['policy_number'] ?? '')),
            'effective_date' => self::dateValue($policy['effective_date'] ?? null),
            'expiration_date' => self::dateValue($policy['expiration_date'] ?? null),
            'combined_single_limit' => self::findCoverageValue($coverage, ['combined_single_limit', 'combined_single', 'combined_single_limit_each_accident']),
            'operations' => $vehicleLines ? "Scheduled autos:\n" . implode("\n", $vehicleLines) : '',
            'certificate_holder' => $holderText,
            'authorized_rep' => '',
        ];

        $pdf = new Fpdi();
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $count = $pdf->setSourceFile($template);
        for ($n = 1; $n <= $count; $n++) {
            $tpl = $pdf->importPage($n);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
            if ($n !== 1) continue;

            self::draw($pdf,$data['certificate_date'],184.0,13.2,27.0,4.0,6.5,1);
            self::draw($pdf,$data['producer_name'],7.8,46.5,96.0,5.0,7.0,1);
            self::draw($pdf,$data['contact_name'],123.0,44.5,64.0,3.5,6.5,1);
            self::draw($pdf,$data['contact_phone'],123.0,48.0,38.0,3.5,6.2,1);
            self::draw($pdf,$data['contact_email'],123.0,52.3,65.0,3.5,6.0,1);
            self::draw($pdf,$data['insured_name'],7.8,66.5,96.0,4.5,7.0,1);
            self::draw($pdf,$data['insured_address'],7.8,70.4,96.0,11.0,6.5,3);
            self::draw($pdf,$data['insurer_name'],123.5,60.0,72.0,3.4,6.0,1);
            self::draw($pdf,$data['naic_number'],194.0,60.0,15.5,3.4,6.0,1);
            self::draw($pdf,$data['certificate_number'],93.0,86.4,48.0,4.0,6.5,1);
            self::draw($pdf,$data['revision_number'],183.5,86.4,24.0,4.0,6.5,1);
            self::draw($pdf,$data['policy_number'],76.5,136.2,39.0,4.0,6.6,1);
            self::draw($pdf,$data['effective_date'],117.8,136.2,16.5,4.0,5.8,1);
            self::draw($pdf,$data['expiration_date'],134.8,136.2,17.0,4.0,5.8,1);
            self::draw($pdf,$data['combined_single_limit'],182.8,136.2,23.0,4.0,6.2,1);
            self::draw($pdf,$data['operations'],7.8,203.3,196.0,17.0,7.0,4);
            self::draw($pdf,$data['certificate_holder'],7.8,233.5,98.0,22.0,7.0,5);
            self::draw($pdf,$data['authorized_rep'],109.5,254.0,96.0,5.0,6.5,1);
        }

        $binary = $pdf->Output('S');
        if (!is_string($binary) || !str_starts_with($binary, '%PDF-')) throw new RuntimeException('PDF generation did not return a valid PDF.');
        if (file_put_contents($outputPath, $binary, LOCK_EX) === false) throw new RuntimeException('Could not save the generated certificate. Check storage permissions.');
        @chmod($outputPath, 0640);
    }

    public static function findCoverageValue(array $source, array $keys): string
    {
        $wanted = array_map(static fn(string $k): string => self::normalKey($k), $keys);
        foreach ($source as $key => $value) {
            if (in_array(self::normalKey((string)$key), $wanted, true) && is_scalar($value)) return trim((string)$value);
            if (is_array($value)) {
                $result = self::findCoverageValue($value, $keys);
                if ($result !== '') return $result;
            }
        }
        return '';
    }

    private static function normalKey(string $key): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $key));
    }

    private static function dateValue(mixed $value): string
    {
        if (!$value) return '';
        $time = strtotime((string)$value);
        return $time === false ? '' : date('m/d/Y', $time);
    }

    private static function pdfText(string $text): string
    {
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return $out === false ? '' : $out;
    }

    private static function sub(string $text, int $start, int $length): string
    {
        if (function_exists('mb_substr')) return mb_substr($text, $start, $length, 'UTF-8');
        $out = iconv_substr($text, $start, $length, 'UTF-8');
        return $out === false ? substr($text, $start, $length) : $out;
    }

    private static function draw(Fpdi $pdf, string $text, float $x, float $y, float $width, float $height, float $size = 7.0, int $maxLines = 2): void
    {
        if (trim($text) === '') return;
        $pdf->SetFont('Helvetica', '', $size);
        $pdf->SetTextColor(10, 10, 10);
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            if (!$words || $words === ['']) { $lines[] = ''; continue; }
            $line = '';
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($pdf->GetStringWidth(self::pdfText($candidate)) <= $width || $line === '') $line = $candidate;
                else { $lines[] = $line; $line = $word; }
            }
            $lines[] = $line;
        }
        $lineHeight = max(3.0, $size * 0.45);
        $allowed = min($maxLines, max(1, (int)floor($height / $lineHeight)));
        if (count($lines) > $allowed) {
            $lines = array_slice($lines, 0, $allowed);
            $last = array_pop($lines) ?? '';
            while ($last !== '' && $pdf->GetStringWidth(self::pdfText($last . '...')) > $width) {
                $chars = function_exists('mb_strlen') ? mb_strlen($last, 'UTF-8') : (iconv_strlen($last, 'UTF-8') ?: strlen($last));
                $last = self::sub($last, 0, max(0, $chars - 1));
            }
            $lines[] = rtrim($last) . '...';
        }
        foreach ($lines as $index => $line) {
            $pdf->SetXY($x, $y + ($index * $lineHeight));
            $pdf->Cell($width, $lineHeight, self::pdfText($line), 0, 0, 'L', false);
        }
    }
}
