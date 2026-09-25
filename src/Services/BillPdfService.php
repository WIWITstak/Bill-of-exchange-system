<?php

namespace OGAS\Services;

use OGAS\Models\Bill;
use OGAS\Models\User;
use OGAS\Models\Company;

// Подключаем TCPDF через автозагрузчик Composer
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

// Если TCPDF не доступен через автозагрузчик, пытаемся подключить напрямую
if (!class_exists('TCPDF')) {
    // Попытка подключить TCPDF напрямую
    $tcpdfPath = __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($tcpdfPath)) {
        require_once $tcpdfPath;
    } else {
        throw new \Exception('TCPDF library not found. Please run: composer require tecnickcom/tcpdf');
    }
}

/**
 * Сервис для генерации PDF векселей
 */
class BillPdfService
{
    /**
     * Генерирует PDF документ векселя
     */
    public static function generatePdf(Bill $bill, ?string $outputPath = null): string
    {
        $issuer = User::findById($bill->getIssuerId());
        $holder = User::findById($bill->getHolderId());
        
        if (!$issuer || !$holder) {
            throw new \Exception('Не найдены данные пользователей векселя');
        }
        
        // Получаем информацию о компаниях (для юридических лиц)
        $issuerCompany = $issuer->getUserType() === 'legal' ? Company::findByUserId($issuer->getId()) : null;
        $holderCompany = $holder->getUserType() === 'legal' ? Company::findByUserId($holder->getId()) : null;
        
        // Проверяем, что TCPDF доступен
        if (!class_exists('TCPDF')) {
            throw new \Exception('TCPDF library not found. Please install it using: composer require tecnickcom/tcpdf');
        }
        
        // Определяем константы, если они не определены
        if (!defined('PDF_PAGE_ORIENTATION')) {
            define('PDF_PAGE_ORIENTATION', 'P'); // Portrait
        }
        if (!defined('PDF_UNIT')) {
            define('PDF_UNIT', 'mm');
        }
        if (!defined('PDF_PAGE_FORMAT')) {
            define('PDF_PAGE_FORMAT', 'A4');
        }
        
        // Создаем PDF документ
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Удаляем заголовок и футер по умолчанию
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Устанавливаем метаданные
        $pdf->SetCreator('ОГАС');
        $pdf->SetAuthor('ОГАС');
        $pdf->SetTitle('Вексель #' . $bill->getId());
        $pdf->SetSubject('Вексель');
        
        // Добавляем страницу
        $pdf->AddPage();
        
        // Устанавливаем шрифт
        $pdf->SetFont('dejavusans', '', 12);
        
        // Заголовок
        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->Cell(0, 15, 'ВЕКСЕЛЬ', 0, 1, 'C');
        $pdf->Ln(5);
        
        // Номер векселя
        $pdf->SetFont('dejavusans', '', 12);
        $pdf->Cell(0, 10, '№ ' . $bill->getId(), 0, 1, 'R');
        $pdf->Ln(5);
        
        // Информация о векселе
        $pdf->SetFont('dejavusans', '', 11);
        
        // Дата выдачи
        $issueDate = date('d.m.Y', strtotime($bill->getIssueDate()));
        $pdf->Cell(0, 8, 'Дата выдачи: ' . $issueDate, 0, 1, 'L');
        
        // Дата погашения
        $maturityDate = date('d.m.Y', strtotime($bill->getMaturityDate()));
        $pdf->Cell(0, 8, 'Дата погашения: ' . $maturityDate, 0, 1, 'L');
        
        // Номинал
        $nominal = number_format($bill->getNominal(), 2, ',', ' ');
        $nominalText = self::numberToWords($bill->getNominal());
        $pdf->Cell(0, 8, 'Номинал: ' . $nominal . ' ₽ (' . $nominalText . ')', 0, 1, 'L');
        
        $pdf->Ln(5);
        
        // Векселедатель
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, 'Векселедатель:', 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 11);
        
        if ($issuerCompany) {
            $pdf->Cell(0, 6, $issuerCompany->getName(), 0, 1, 'L');
            if ($issuerCompany->getAddress()) {
                $pdf->Cell(0, 6, 'Адрес: ' . $issuerCompany->getAddress(), 0, 1, 'L');
            }
            if ($issuerCompany->getOkvedCode()) {
                $pdf->Cell(0, 6, 'ОКВЭД: ' . $issuerCompany->getOkvedCode(), 0, 1, 'L');
            }
        } else {
            $pdf->Cell(0, 6, $issuer->getFullName(), 0, 1, 'L');
        }
        $pdf->Cell(0, 6, 'Email: ' . $issuer->getEmail(), 0, 1, 'L');
        
        $pdf->Ln(5);
        
        // Векселедержатель
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, 'Векселедержатель:', 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 11);
        
        if ($holderCompany) {
            $pdf->Cell(0, 6, $holderCompany->getName(), 0, 1, 'L');
            if ($holderCompany->getAddress()) {
                $pdf->Cell(0, 6, 'Адрес: ' . $holderCompany->getAddress(), 0, 1, 'L');
            }
            if ($holderCompany->getOkvedCode()) {
                $pdf->Cell(0, 6, 'ОКВЭД: ' . $holderCompany->getOkvedCode(), 0, 1, 'L');
            }
        } else {
            $pdf->Cell(0, 6, $holder->getFullName(), 0, 1, 'L');
        }
        $pdf->Cell(0, 6, 'Email: ' . $holder->getEmail(), 0, 1, 'L');
        
        $pdf->Ln(10);
        
        // Текст обязательства
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(0, 6, 'Настоящим векселем векселедатель обязуется уплатить векселедержателю сумму в размере ' . $nominal . ' рублей (' . $nominalText . ') не позднее ' . $maturityDate . '.', 0, 'L');
        
        $pdf->Ln(10);
        
        // Статус
        $statusText = [
            'active' => 'Активен',
            'paid' => 'Погашен',
            'overdue' => 'Просрочен',
            'cancelled' => 'Аннулирован'
        ];
        $pdf->Cell(0, 8, 'Статус: ' . ($statusText[$bill->getStatus()] ?? $bill->getStatus()), 0, 1, 'L');
        
        if ($bill->getPaymentDate()) {
            $paymentDate = date('d.m.Y', strtotime($bill->getPaymentDate()));
            $pdf->Cell(0, 8, 'Дата погашения: ' . $paymentDate, 0, 1, 'L');
        }
        
        $pdf->Ln(10);
        
        // Подпись
        $pdf->Cell(0, 8, '___________________________', 0, 1, 'R');
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(0, 5, 'Подпись векселедателя', 0, 1, 'R');
        
        // Сохраняем или возвращаем PDF
        if ($outputPath) {
            $pdf->Output($outputPath, 'F');
            return $outputPath;
        } else {
            return $pdf->Output('', 'S');
        }
    }
    
    /**
     * Преобразует число в прописью (русский язык)
     */
    private static function numberToWords(float $number): string
    {
        $ones = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
        $tens = ['', 'десять', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
        $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        
        $rubles = floor($number);
        $kopecks = round(($number - $rubles) * 100);
        
        $result = '';
        
        // Обрабатываем рубли
        if ($rubles == 0) {
            $result = 'ноль';
        } else {
            // Миллионы
            $millions = floor($rubles / 1000000);
            if ($millions > 0) {
                $result .= self::convertThreeDigits($millions, $hundreds, $tens, $teens, $ones);
                $result .= self::pluralize($millions, 'миллион', 'миллиона', 'миллионов') . ' ';
                $rubles %= 1000000;
            }
            
            // Тысячи
            $thousands = floor($rubles / 1000);
            if ($thousands > 0) {
                $result .= self::convertThreeDigits($thousands, $hundreds, $tens, $teens, $ones, true);
                $result .= self::pluralize($thousands, 'тысяча', 'тысячи', 'тысяч') . ' ';
                $rubles %= 1000;
            }
            
            // Единицы
            if ($rubles > 0) {
                $result .= self::convertThreeDigits($rubles, $hundreds, $tens, $teens, $ones);
            }
        }
        
        $result = trim($result) . ' ' . self::pluralize($rubles, 'рубль', 'рубля', 'рублей');
        
        // Копейки
        if ($kopecks > 0) {
            $result .= ' ' . self::convertThreeDigits($kopecks, $hundreds, $tens, $teens, $ones);
            $result .= ' ' . self::pluralize($kopecks, 'копейка', 'копейки', 'копеек');
        }
        
        return mb_strtoupper(mb_substr($result, 0, 1)) . mb_substr($result, 1);
    }
    
    private static function convertThreeDigits(int $number, array $hundreds, array $tens, array $teens, array $ones, bool $isThousand = false): string
    {
        $result = '';
        
        $h = floor($number / 100);
        $t = floor(($number % 100) / 10);
        $o = $number % 10;
        
        if ($h > 0) {
            $result .= $hundreds[$h] . ' ';
        }
        
        if ($t == 1) {
            $result .= $teens[$o] . ' ';
        } else {
            if ($t > 0) {
                $result .= $tens[$t] . ' ';
            }
            if ($o > 0) {
                if ($isThousand && ($o == 1 || $o == 2)) {
                    $result .= ($o == 1 ? 'одна' : 'две') . ' ';
                } else {
                    $result .= $ones[$o] . ' ';
                }
            }
        }
        
        return $result;
    }
    
    private static function pluralize(int $number, string $one, string $two, string $many): string
    {
        $mod10 = $number % 10;
        $mod100 = $number % 100;
        
        if ($mod10 == 1 && $mod100 != 11) {
            return $one;
        } elseif (in_array($mod10, [2, 3, 4]) && !in_array($mod100, [12, 13, 14])) {
            return $two;
        } else {
            return $many;
        }
    }
    
    /**
     * Сохраняет PDF векселя в файл и обновляет путь в базе данных
     */
    public static function savePdf(Bill $bill, ?string $directory = null): string
    {
        if (!$directory) {
            $directory = __DIR__ . '/../../storage/bills';
        }
        
        // Создаем директорию, если её нет
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $filename = 'bill_' . $bill->getId() . '.pdf';
        $filepath = $directory . '/' . $filename;
        
        // Генерируем PDF
        self::generatePdf($bill, $filepath);
        
        // Сохраняем относительный путь в базе данных (относительно корня проекта)
        $relativePath = 'storage/bills/' . $filename;
        $bill->setFilePath($relativePath);
        $bill->saveFilePath();
        
        return $filepath;
    }
    
    /**
     * Проверяет, существует ли PDF файл для векселя
     */
    public static function pdfExists(Bill $bill, ?string $directory = null): bool
    {
        // Если есть сохранённый путь в базе данных, проверяем его
        if ($bill->getFilePath()) {
            $savedPath = __DIR__ . '/../../' . $bill->getFilePath();
            if (file_exists($savedPath)) {
                return true;
            }
        }
        
        // Иначе проверяем стандартный путь
        if (!$directory) {
            $directory = __DIR__ . '/../../storage/bills';
        }
        
        $filename = 'bill_' . $bill->getId() . '.pdf';
        $filepath = $directory . '/' . $filename;
        
        return file_exists($filepath);
    }
    
    /**
     * Получает путь к PDF файлу векселя
     */
    public static function getPdfPath(Bill $bill, ?string $directory = null): string
    {
        // Если есть сохранённый путь в базе данных, используем его
        if ($bill->getFilePath()) {
            $savedPath = __DIR__ . '/../../' . $bill->getFilePath();
            if (file_exists($savedPath)) {
                return $savedPath;
            }
        }
        
        // Иначе используем стандартный путь
        if (!$directory) {
            $directory = __DIR__ . '/../../storage/bills';
        }
        
        $filename = 'bill_' . $bill->getId() . '.pdf';
        return $directory . '/' . $filename;
    }
}

