<?php
declare(strict_types=1);

namespace AcePortal;

use RuntimeException;

final class CabCardPdf
{
    public static function stream(array $vehicles, array $policy, array $insured, array $agencyConfig, bool $download = false): void
    {
        $autoload = APP_ROOT . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('PDF libraries are not installed. Run composer install in the project folder.');
        }
        require_once $autoload;
        if (!$vehicles) throw new RuntimeException('No vehicle records were supplied for this cab card.');

        $pdf = new \FPDF('L', 'mm', 'Letter');
        $pdf->SetCreator('Ace Insurance Portal');
        $pdf->SetTitle('Insurance Identification Card - ' . (string)($policy['policy_number'] ?? ''));
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);

        foreach ($vehicles as $vehicle) {
            $pdf->AddPage('L', 'Letter');
            self::drawCard($pdf, $vehicle, $policy, $insured, $agencyConfig);
        }

        $binary = $pdf->Output('S');
        if (!is_string($binary) || !str_starts_with($binary, '%PDF-')) {
            throw new RuntimeException('Cab card generation did not return a valid PDF.');
        }
        $safePolicy = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($policy['policy_number'] ?? 'policy')) ?: 'policy';
        $filename = 'cab-cards-' . trim($safePolicy, '-') . '.pdf';
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($binary));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        echo $binary;
        exit;
    }

    private static function drawCard(\FPDF $pdf, array $vehicle, array $policy, array $insured, array $agencyConfig): void
    {
        $x = 14.0;
        $y = 10.0;
        $w = 251.0;
        $h = 195.0;
        $right = $x + $w;
        $policyNumber = trim((string)($policy['policy_number'] ?? ''));
        $carrier = trim((string)($policy['insurer_a_name'] ?? ''));
        if ($carrier === '') $carrier = trim((string)($policy['carrier_name'] ?? ''));
        $state = strtoupper(trim((string)($insured['state'] ?? '')));
        $companyNumber = trim((string)($policy['insurer_a_naic'] ?? ''));
        if ($companyNumber === '') {
            $companyNumber = self::findCoverageValue($policy['coverage_data'] ?? null, ['naic_number', 'company_number', 'insurer_naic', 'naic']);
        }
        $businessLine = strtolower(trim((string)($policy['business_line'] ?? '')));
        $vehicleType = strtolower(trim((string)($vehicle['vehicle_type'] ?? '')));
        $commercial = str_contains($businessLine, 'commercial')
            || str_contains($businessLine, 'auto')
            || str_contains($businessLine, 'truck')
            || str_contains($businessLine, 'liability')
            || str_contains($vehicleType, 'truck')
            || str_contains($vehicleType, 'tractor')
            || str_contains($vehicleType, 'trailer');
        $agencyName = trim((string)($agencyConfig['agency_name'] ?? $agencyConfig['from_name'] ?? ''));
        $agencyAddress = trim((string)($agencyConfig['agency_address'] ?? $agencyConfig['address'] ?? ''));
        $agencyPhone = trim((string)($agencyConfig['contact_phone'] ?? $agencyConfig['phone'] ?? ''));
        $agencyEmail = trim((string)($agencyConfig['contact_email'] ?? $agencyConfig['from_email'] ?? ''));
        $insuredAddress = implode(', ', array_filter([
            trim((string)($insured['address_line1'] ?? '')),
            trim((string)($insured['address_line2'] ?? '')),
            trim((string)($insured['city'] ?? '')),
            trim((string)($insured['state'] ?? '')),
            trim((string)($insured['postal_code'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));
        $makeModel = trim((string)($vehicle['make'] ?? '') . ' ' . (string)($vehicle['model'] ?? ''));
        $vehicleYear = trim((string)($vehicle['model_year'] ?? ''));
        $vin = trim((string)($vehicle['vin'] ?? ''));

        // Outer card border and heading.
        $pdf->SetDrawColor(30, 41, 59);
        $pdf->SetLineWidth(0.45);
        $pdf->Rect($x, $y, $w, $h);
        $pdf->SetTextColor(20, 24, 31);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($x + 5, $y + 5);
        $pdf->Cell(48, 6, ($state !== '' ? $state : '--') . ' (State)', 0, 0, 'L');
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->SetXY($x + 60, $y + 4);
        $pdf->Cell(132, 7, 'INSURANCE IDENTIFICATION CARD', 0, 0, 'C');

        // Company number, insurer, and type.
        self::field($pdf, 'COMPANY NUMBER', $companyNumber, $x + 5, $y + 17, 48);
        self::field($pdf, 'COMPANY', $carrier, $x + 78, $y + 17, 93);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetXY($x + 192, $y + 17);
        $pdf->Cell(54, 4, 'TYPE', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 8.5);
        $typeLabel = ($commercial ? '[X] Commercial' : '[ ] Commercial') . '   ' . ($commercial ? '[ ] Personal' : '[X] Personal');
        $pdf->SetXY($x + 192, $y + 21);
        $pdf->MultiCell(54, 4, $typeLabel, 0, 'L');
        $pdf->Line($x + 5, $y + 33, $right - 5, $y + 33);

        // Policy period.
        self::field($pdf, 'POLICY NUMBER', $policyNumber, $x + 5, $y + 38, 55);
        self::field($pdf, 'EFFECTIVE DATE', self::dateValue($policy['effective_date'] ?? null), $x + 68, $y + 38, 52);
        self::field($pdf, 'EXPIRATION DATE', self::dateValue($policy['expiration_date'] ?? null), $x + 132, $y + 38, 55);
        $pdf->Line($x + 5, $y + 55, $right - 5, $y + 55);

        // Vehicle identity.
        self::field($pdf, 'YEAR', $vehicleYear, $x + 5, $y + 60, 35);
        self::field($pdf, 'MAKE / MODEL', $makeModel, $x + 68, $y + 60, 67);
        self::field($pdf, 'VEHICLE IDENTIFICATION NUMBER', $vin, $x + 132, $y + 60, 112);
        $pdf->Line($x + 5, $y + 78, $right - 5, $y + 78);

        // Issuing office and insured.
        self::field($pdf, 'AGENCY / ISSUING OFFICE', $agencyName, $x + 5, $y + 84, 105);
        self::multiline($pdf, $agencyAddress, $x + 5, $y + 92, 105, 4.0);
        $contactY = $y + 92 + (substr_count($agencyAddress, "\n") + 1) * 4.0;
        if ($agencyPhone !== '') self::field($pdf, 'PHONE', $agencyPhone, $x + 5, $contactY, 105);
        if ($agencyEmail !== '') self::field($pdf, 'EMAIL', $agencyEmail, $x + 5, $contactY + 8, 105);
        self::field($pdf, 'INSURED', (string)($insured['legal_name'] ?? ''), $x + 126, $y + 84, 115);
        self::multiline($pdf, $insuredAddress, $x + 126, $y + 92, 115, 4.0);

        // Informational card footer, similar in hierarchy to the supplied reference.
        $pdf->Line($x + 5, $y + 143, $right - 5, $y + 143);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY($x + 6, $y + 148);
        $pdf->Cell($w - 12, 5, 'THIS CARD MUST BE KEPT IN THE INSURED VEHICLE AND PRESENTED UPON DEMAND', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY($x + 6, $y + 155);
        $pdf->MultiCell($w - 12, 4.2, 'IN CASE OF ACCIDENT, REPORT THE ACCIDENT TO YOUR AGENT OR INSURANCE COMPANY AS SOON AS POSSIBLE.', 0, 'L');
        $pdf->SetXY($x + 12, $y + 166);
        $pdf->MultiCell($w - 24, 4.5, '1. Record the name and address of each driver, passenger, and witness.' . "\n" . '2. Record the insurance company and policy number for each vehicle involved.', 0, 'L');
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->SetXY($x + 5, $y + 184);
        $pdf->Cell($w - 10, 4, 'Insurance Identification Card - generated from records on file; confirm with the issuing agency.', 0, 0, 'C');
    }

    private static function field(\FPDF $pdf, string $label, string $value, float $x, float $y, float $width): void
    {
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetTextColor(20, 24, 31);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, 4, strtoupper($label), 0, 1, 'L');
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY($x, $y + 4.5);
        $pdf->MultiCell($width, 4.2, self::pdfText($value), 0, 'L');
    }

    private static function multiline(\FPDF $pdf, string $value, float $x, float $y, float $width, float $lineHeight): void
    {
        if (trim($value) === '') return;
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($width, $lineHeight, self::pdfText($value), 0, 'L');
    }

    private static function dateValue(mixed $value): string
    {
        if (!$value) return '';
        $time = strtotime((string)$value);
        return $time === false ? '' : date('n/j/Y', $time);
    }

    private static function pdfText(string $value): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        return $converted === false ? '' : $converted;
    }

    private static function findCoverageValue(mixed $raw, array $wantedKeys): string
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $raw = $decoded;
        }
        if (!is_array($raw)) return '';
        $wanted = array_map(static fn(string $key): string => strtolower((string)preg_replace('/[^a-z0-9]/i', '', $key)), $wantedKeys);
        foreach ($raw as $key => $value) {
            $normalized = strtolower((string)preg_replace('/[^a-z0-9]/i', '', (string)$key));
            if (in_array($normalized, $wanted, true) && is_scalar($value)) return trim((string)$value);
            if (is_array($value)) {
                $found = self::findCoverageValue($value, $wantedKeys);
                if ($found !== '') return $found;
            }
        }
        return '';
    }
}
