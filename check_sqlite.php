<?php
/**
 * Скрипт для проверки наличия SQLite расширений в PHP
 */

echo "=== Проверка SQLite расширений ===\n\n";

// Проверка PDO SQLite
if (extension_loaded('pdo_sqlite')) {
    echo "✓ PDO SQLite: ЗАГРУЖЕН\n";
} else {
    echo "✗ PDO SQLite: НЕ ЗАГРУЖЕН\n";
}

// Проверка SQLite3
if (extension_loaded('sqlite3')) {
    echo "✓ SQLite3: ЗАГРУЖЕН\n";
} else {
    echo "✗ SQLite3: НЕ ЗАГРУЖЕН\n";
}

// Проверка PDO
if (extension_loaded('pdo')) {
    echo "✓ PDO: ЗАГРУЖЕН\n";
} else {
    echo "✗ PDO: НЕ ЗАГРУЖЕН\n";
}

echo "\n=== Информация о PHP ===\n";
echo "PHP версия: " . PHP_VERSION . "\n";
echo "Путь к php.ini: " . php_ini_loaded_file() . "\n";
echo "Дополнительные ini файлы: " . php_ini_scanned_files() . "\n";

echo "\n=== Инструкция ===\n";
$iniFile = php_ini_loaded_file();
if ($iniFile) {
    echo "1. Откройте файл: {$iniFile}\n";
    echo "2. Найдите строки (или добавьте в конец файла):\n";
    echo "   extension=pdo_sqlite\n";
    echo "   extension=sqlite3\n";
    echo "3. Убедитесь, что перед строками НЕТ символа ; (точка с запятой)\n";
    echo "4. Сохраните файл\n";
    echo "5. Перезапустите PHP (если используется как сервис) или перезапустите терминал\n";
} else {
    echo "Не удалось найти php.ini файл.\n";
    echo "Попробуйте выполнить: php --ini\n";
}

