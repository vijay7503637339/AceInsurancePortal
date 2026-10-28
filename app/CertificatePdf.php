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
        $insuranceTypes = is_array($coverage['insurance_types'] ?? null) ? $coverage['insurance_types'] : [];
        $insuranceCarriers = is_array($coverage['insurance_carriers'] ?? null) ? $coverage['insurance_carriers'] : [];
        $coverageOptions = is_array($coverage['coverage_options'] ?? null) ? $coverage['coverage_options'] : [];
        $cglData = is_array($coverage['commercial_general_liability'] ?? null) ? $coverage['commercial_general_liability'] : [];
        $cglLimits = is_array($cglData['limits'] ?? null) ? $cglData['limits'] : [];
        $physicalDamageData = is_array($coverage['physical_damage'] ?? null) ? $coverage['physical_damage'] : [];
        $motorTruckCargoData = is_array($coverage['motor_truck_cargo'] ?? null) ? $coverage['motor_truck_cargo'] : [];
        $insurers = [];
        foreach (range('A', 'F') as $letter) {
            $field = 'insurer_' . strtolower($letter);
            $name = trim((string)($policy[$field . '_name'] ?? ''));
            $naic = trim((string)($policy[$field . '_naic'] ?? ''));
            if ($letter === 'A') {
                if ($name === '') $name = trim((string)($policy['carrier_name'] ?? ''));
                if ($naic === '') $naic = self::findCoverageValue($coverage, ['naic_number', 'insurer_naic', 'naic']);
            }
            $insurers[] = ['name' => $name, 'naic' => $naic];
        }

        $agency = trim((string)($mailConfig['company_name'] ?? ''));
        if ($agency === '') $agency = trim((string)($mailConfig['agency_name'] ?? $policy['broker_name'] ?? $mailConfig['from_name'] ?? ''));
        $agencyAddress = trim((string)($mailConfig['company_address'] ?? ''));
        $signatureName = trim((string)($mailConfig['authorized_signature_name'] ?? ''));
        $signatureFile = trim((string)($mailConfig['authorized_signature_file'] ?? ''));
        $signaturePath = '';
        if (preg_match('/^[a-f0-9]{32}\.(?:png|jpg)$/', $signatureFile)) {
            $candidate = APP_ROOT . '/storage/private/company-signatures/' . $signatureFile;
            if (is_file($candidate) && is_readable($candidate)) $signaturePath = $candidate;
        }
        // The broker entered on the customer form is also the certificate holder.
        $holderText = trim((string)($holder['broker_name'] ?? $holder['certificate_holder_name'] ?? ''));
        $holderAddress = trim((string)($holder['broker_address'] ?? $holder['certificate_holder_address'] ?? ''));
        if ($holderAddress !== '') $holderText .= ($holderText !== '' ? "\n" : '') . $holderAddress;

        $data = [
            'certificate_date' => date('m/d/Y'),
            'producer_name' => $agency,
            'producer_address' => $agencyAddress,
            'contact_name' => trim((string)($mailConfig['contact_person'] ?? $mailConfig['contact_name'] ?? '')),
            'contact_phone' => trim((string)($mailConfig['phone_number'] ?? $mailConfig['contact_phone'] ?? '')),
            'contact_email' => trim((string)($mailConfig['email_address'] ?? $mailConfig['contact_email'] ?? $mailConfig['from_email'] ?? '')),
            'insured_name' => trim((string)($insured['legal_name'] ?? '')),
            'insured_address' => $insuredAddress,
            'insurers' => $insurers,
            'certificate_number' => trim((string)($policy['certificate_number'] ?? '')),
            'revision_number' => trim((string)($policy['revision_number'] ?? '')),
            'policy_number' => trim((string)($policy['policy_number'] ?? '')),
            'effective_date' => self::dateValue($policy['effective_date'] ?? null),
            'expiration_date' => self::dateValue($policy['expiration_date'] ?? null),
            'combined_single_limit' => self::formatLimit(array_key_exists('combined_single_limit', $coverage) ? trim((string)$coverage['combined_single_limit']) : self::findCoverageValue($coverage, ['combined_single', 'combined_single_limit_each_accident'])),
            'operations' => $vehicleLines ? "Scheduled autos:\n" . implode("\n", $vehicleLines) : '',
            'certificate_holder' => $holderText,
            'authorized_rep' => $signatureName,
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
            self::draw($pdf,$data['producer_name'],7.8,46.5,96.0,4.0,7.0,1);
            self::draw($pdf,$data['producer_address'],7.8,50.3,96.0,11.0,6.0,3);
            self::draw($pdf,$data['contact_name'],123.0,44.5,64.0,3.5,6.5,1);
            self::draw($pdf,$data['contact_phone'],123.0,48.0,38.0,3.5,6.2,1);
            self::draw($pdf,$data['contact_email'],123.0,52.3,65.0,3.5,6.0,1);
            self::draw($pdf,$data['insured_name'],7.8,66.5,96.0,4.5,7.0,1);
            self::draw($pdf,$data['insured_address'],7.8,70.4,96.0,11.0,6.5,3);
            foreach ($data['insurers'] as $index => $insurer) {
                $insurerY = 60.0 + ($index * 4.4);
                self::draw($pdf, $insurer['name'], 123.5, $insurerY, 72.0, 3.4, 6.0, 1);
                self::draw($pdf, $insurer['naic'], 194.0, $insurerY, 15.5, 3.4, 6.0, 1);
            }
            self::draw($pdf,$data['certificate_number'],93.0,86.4,48.0,4.0,6.5,1);
            self::draw($pdf,$data['revision_number'],183.5,86.4,24.0,4.0,6.5,1);
            self::draw($pdf,$data['policy_number'],76.5,136.2,39.0,4.0,6.6,1);
            self::draw($pdf,$data['effective_date'],117.8,136.2,16.5,4.0,5.8,1);
            self::draw($pdf,$data['expiration_date'],134.8,136.2,17.0,4.0,5.8,1);
            self::draw($pdf,$data['combined_single_limit'],182.8,136.2,23.0,4.0,6.2,1);

            // ACORD 25 INS LTR column maps each selected coverage to its selected insurer letter.
            $typeRows = [
                'commercial_general_liability' => 109.2,
                'automobile_liability' => 136.2,
                'umbrella_liability' => 158.7,
                'excess_liability' => 164.0,
                'workers_compensation_employers_liability' => 173.6,
                'physical_damage' => 186.2,
                'motor_truck_cargo' => 194.7,
            ];
            foreach ($typeRows as $typeKey => $rowY) {
                if (!in_array($typeKey, $insuranceTypes, true)) continue;
                $letter = strtoupper(trim((string)($insuranceCarriers[$typeKey] ?? 'A')));
                if (!in_array($letter, ['A','B','C','D','E','F'], true)) $letter = 'A';
                self::draw($pdf, $letter, 7.0, $rowY, 2.8, 3.2, 5.7, 1);
            }

            // Commercial General Liability has its own policy number, dates and six limit values.
            if (in_array('commercial_general_liability', $insuranceTypes, true)) {
                self::draw($pdf, trim((string)($cglData['policy_number'] ?? '')), 76.5, 109.2, 39.0, 4.0, 6.2, 1);
                self::draw($pdf, self::dateValue($cglData['effective_date'] ?? null), 117.8, 109.2, 16.5, 4.0, 5.5, 1);
                self::draw($pdf, self::dateValue($cglData['expiration_date'] ?? null), 134.8, 109.2, 17.0, 4.0, 5.5, 1);
                $cglLimitRows = [
                    'each_occurrence' => 109.2,
                    'damage_to_rented_premises' => 113.3,
                    'medical_expense' => 117.4,
                    'personal_advertising_injury' => 121.5,
                    'general_aggregate' => 125.6,
                    'products_completed_operations_aggregate' => 129.7,
                ];
                foreach ($cglLimitRows as $limitKey => $limitY) {
                    self::draw($pdf, self::formatLimit(trim((string)($cglLimits[$limitKey] ?? ''))), 182.8, $limitY, 23.0, 3.6, 5.6, 1);
                }
            }

            // Physical Damage and Motor Truck Cargo occupy the unused rows just above Description of Operations.
            if (in_array('physical_damage', $insuranceTypes, true)) {
                self::draw($pdf, 'PHYSICAL DAMAGE', 18.0, 186.2, 55.0, 3.0, 5.8, 1);
                self::draw($pdf, trim((string)($physicalDamageData['description'] ?? '')), 18.0, 189.0, 55.0, 3.2, 4.6, 1);
                self::draw($pdf, trim((string)($physicalDamageData['policy_number'] ?? '')), 76.5, 187.1, 39.0, 3.6, 5.4, 1);
                self::draw($pdf, self::dateValue($physicalDamageData['effective_date'] ?? null), 117.8, 187.1, 16.5, 3.6, 5.1, 1);
                self::draw($pdf, self::dateValue($physicalDamageData['expiration_date'] ?? null), 134.8, 187.1, 17.0, 3.6, 5.1, 1);
                self::draw($pdf, self::formatLimit(trim((string)($physicalDamageData['amount'] ?? ''))), 182.8, 187.1, 23.0, 3.6, 5.5, 1);
            }
            if (in_array('motor_truck_cargo', $insuranceTypes, true)) {
                self::draw($pdf, 'MOTOR TRUCK CARGO', 18.0, 194.7, 55.0, 3.0, 5.8, 1);
                self::draw($pdf, trim((string)($motorTruckCargoData['policy_number'] ?? '')), 76.5, 195.6, 39.0, 3.6, 5.4, 1);
                self::draw($pdf, self::dateValue($motorTruckCargoData['effective_date'] ?? null), 117.8, 195.6, 16.5, 3.6, 5.1, 1);
                self::draw($pdf, self::dateValue($motorTruckCargoData['expiration_date'] ?? null), 134.8, 195.6, 17.0, 3.6, 5.1, 1);
                self::draw($pdf, self::formatLimit(trim((string)($motorTruckCargoData['amount'] ?? ''))), 153.5, 195.6, 18.0, 3.6, 5.5, 1);
                self::draw($pdf, trim((string)($motorTruckCargoData['description'] ?? '')), 172.5, 195.6, 33.0, 3.6, 5.2, 1);
            }

            // Mark only the options selected in the saved policy form.
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'commercial_general_liability', 'claims_made'), 18.0, 113.0);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'commercial_general_liability', 'occur'), 45.0, 113.0);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'commercial_general_liability', 'aggregate_policy'), 15.2, 128.8);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'commercial_general_liability', 'aggregate_project'), 31.2, 128.8);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'commercial_general_liability', 'aggregate_location'), 47.4, 128.8);
            self::draw($pdf, trim((string)($coverage['aggregate_other_description'] ?? '')), 13.0, 131.7, 41.0, 3.7, 5.4, 1);

            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'automobile_liability', 'any_auto'), 15.5, 141.8);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'automobile_liability', 'all_owned_autos'), 15.5, 146.2);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'automobile_liability', 'hired_autos'), 15.5, 150.5);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'automobile_liability', 'scheduled_autos'), 39.3, 146.2);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'automobile_liability', 'non_owned_autos'), 39.3, 150.5);

            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'umbrella_liability', 'occur'), 39.0, 158.8);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'umbrella_liability', 'claims_made'), 39.0, 164.0);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'excess_liability', 'occur'), 39.0, 158.8);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'excess_liability', 'claims_made'), 39.0, 164.0);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'umbrella_liability', 'deductible') || self::hasOption($coverageOptions, 'excess_liability', 'deductible'), 18.2, 168.5);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'umbrella_liability', 'retention') || self::hasOption($coverageOptions, 'excess_liability', 'retention'), 27.5, 168.5);
            self::draw($pdf, trim((string)($coverage['deductible_retention_amount'] ?? '')), 37.0, 168.1, 22.0, 3.6, 5.6, 1);

            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'workers_compensation_employers_liability', 'per_statute'), 161.7, 173.6);
            self::markCheckbox($pdf, self::hasOption($coverageOptions, 'workers_compensation_employers_liability', 'other'), 181.7, 173.6);
            $excludedStatus = (string)($coverage['workers_compensation_excluded'] ?? '');
            if (in_array($excludedStatus, ['yes','no'], true)) {
                self::draw($pdf, strtoupper(substr($excludedStatus, 0, 1)), 53.5, 177.0, 5.0, 3.0, 5.4, 1);
            } elseif ($excludedStatus === 'na') {
                self::draw($pdf, 'N/A', 64.0, 177.0, 8.0, 3.0, 5.0, 1);
            }
            self::draw($pdf, trim((string)($coverage['workers_compensation_other_text'] ?? '')), 184.0, 177.4, 24.0, 4.0, 5.0, 1);

            self::draw($pdf,$data['operations'],7.8,203.3,196.0,17.0,7.0,4);
            self::draw($pdf,$data['certificate_holder'],7.8,233.5,98.0,22.0,7.0,5);
            if ($signaturePath !== '') {
                // Only a validated filename from the private main-admin signature folder is used.
                $pdf->Image($signaturePath, 145.0, 248.0, 42.0, 7.0);
            }
            self::draw($pdf,$data['authorized_rep'],109.5,254.0,96.0,5.0,6.5,1);
        }

        $binary = $pdf->Output('S');
        if (!is_string($binary) || !str_starts_with($binary, '%PDF-')) throw new RuntimeException('PDF generation did not return a valid PDF.');
        if (file_put_contents($outputPath, $binary, LOCK_EX) === false) throw new RuntimeException('Could not save the generated certificate. Check storage permissions.');
        @chmod($outputPath, 0640);
    }

    private static function hasOption(array $options, string $group, string $key): bool
    {
        $selected = $options[$group] ?? [];
        return is_array($selected) && in_array($key, $selected, true);
    }

    private static function markCheckbox(Fpdi $pdf, bool $selected, float $centerX, float $centerY, float $size = 2.3): void
    {
        if (!$selected) return;
        $pdf->SetDrawColor(12, 24, 42);
        $pdf->SetLineWidth(0.42);
        $pdf->Line($centerX - ($size * 0.42), $centerY, $centerX - ($size * 0.08), $centerY + ($size * 0.36));
        $pdf->Line($centerX - ($size * 0.08), $centerY + ($size * 0.36), $centerX + ($size * 0.56), $centerY - ($size * 0.48));
    }

    private static function formatLimit(string $value): string
    {
        $value = trim(str_replace(['$', ',', ' '], '', $value));
        if ($value === '' || !is_numeric($value)) return $value;
        $decimals = str_contains($value, '.') ? 2 : 0;
        return number_format((float)$value, $decimals, '.', ',');
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
