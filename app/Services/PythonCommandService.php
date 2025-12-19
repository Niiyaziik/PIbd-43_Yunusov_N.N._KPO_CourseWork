<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PythonCommandService
{
    public function findPythonCommandWithTensorFlow(array $requiredModules = ['tensorflow', 'pandas', 'numpy', 'sklearn']): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $pythonCommand = $this->findPythonOnWindows($requiredModules);
            if ($pythonCommand) {
                return $pythonCommand;
            }
        }

        return $this->findPythonOnUnix($requiredModules);
    }

    private function findPythonOnWindows(array $requiredModules): ?string
    {
        $localAppData = getenv('LOCALAPPDATA');
        $userName = get_current_user();

        $possiblePython311Paths = [
            $localAppData.'\\Programs\\Python\\Python311\\python.exe',
            'C:\\Users\\'.$userName.'\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
            'C:\\Python311\\python.exe',
            'C:\\Program Files\\Python311\\python.exe',
        ];

        Log::info('Поиск Python 3.11 в следующих путях: '.implode(', ', $possiblePython311Paths));

        foreach ($possiblePython311Paths as $pythonPath) {
            if (file_exists($pythonPath)) {
                Log::info("Найден Python по пути: {$pythonPath}, проверка модулей...");

                if ($this->checkPythonModules($pythonPath, $requiredModules)) {
                    Log::info("Найден Python 3.11 со всеми модулями по пути: {$pythonPath}");

                    return $pythonPath;
                }
            }
        }

        $pyCommands = [
            ['py', '-3.11'],
            ['py', '-3.10'],
            ['py', '-3.9'],
            ['py', '-3'],
        ];

        foreach ($pyCommands as $cmdParts) {
            $cmdStr = escapeshellarg($cmdParts[0]).' '.escapeshellarg($cmdParts[1]);

            if ($this->checkPythonModules($cmdStr, $requiredModules)) {
                Log::info('Найден Python со всеми модулями: '.implode(' ', $cmdParts));

                return implode(' ', $cmdParts);
            }
        }

        return null;
    }

    private function findPythonOnUnix(array $requiredModules): ?string
    {
        $commands = ['python3.11', 'python3', 'python'];

        foreach ($commands as $cmd) {
            $output = [];
            $returnVar = 0;
            exec(escapeshellcmd($cmd).' --version 2>&1', $output, $returnVar);

            if ($returnVar === 0) {
                if ($this->checkPythonModules($cmd, $requiredModules)) {
                    Log::info("Найден Python с TensorFlow: {$cmd}");

                    return $cmd;
                }
            }
        }

        return null;
    }

    private function checkPythonModules(string $pythonCommand, array $requiredModules): bool
    {
        $missingModules = [];
        $hasTensorFlow = false;
        $tfVersion = null;

        foreach ($requiredModules as $module) {
            if ($module === 'tensorflow') {
                $check = $this->checkTensorFlow($pythonCommand);
                if ($check['found']) {
                    $hasTensorFlow = true;
                    $tfVersion = $check['version'];
                } else {
                    $missingModules[] = $module;
                }
            } else {
                $importName = ($module === 'sklearn') ? 'sklearn' : $module;
                $check = shell_exec(
                    (file_exists($pythonCommand) ? escapeshellarg($pythonCommand) : escapeshellcmd($pythonCommand)).
                    ' -c "import '.$importName.'; print(\'OK\')" 2>&1'
                );

                $hasError = $check && (
                    stripos($check, 'No module named') !== false ||
                    stripos($check, 'ModuleNotFoundError') !== false ||
                    (stripos($check, 'Error') !== false && stripos($check, 'ImportError') !== false)
                );

                $hasOK = $check && stripos($check, 'OK') !== false;

                if ($hasError || (! $check && ! $hasOK)) {
                    $missingModules[] = $module;
                    Log::warning("  Модуль {$module} не найден. Вывод: ".substr($check ?? 'нет вывода', 0, 200));
                } else {
                    Log::info("  ✓ Модуль {$module} найден");
                }
            }
        }

        if (empty($missingModules) && $hasTensorFlow) {
            if ($tfVersion) {
                Log::info("Найден Python с TensorFlow {$tfVersion} и всеми модулями");
            }

            return true;
        } else {
            if ($hasTensorFlow) {
                Log::warning("Python найден с TensorFlow {$tfVersion}, но отсутствуют модули: ".implode(', ', $missingModules));
            } else {
                Log::info('Python найден, но TensorFlow отсутствует');
            }

            return false;
        }
    }

    private function checkTensorFlow(string $pythonCommand): array
    {
        $command = (file_exists($pythonCommand) ? escapeshellarg($pythonCommand) : escapeshellcmd($pythonCommand)).
                   ' -c "import tensorflow; print(tensorflow.__version__)" 2>&1';

        $check = shell_exec($command);

        if ($check && preg_match('/\b(\d+\.\d+\.\d+)\b/', $check, $matches)) {
            return [
                'found' => true,
                'version' => $matches[1] ?? null,
            ];
        }

        return ['found' => false, 'version' => null];
    }

    public function buildPythonCommand(
        string $pythonCommand,
        string $scriptPath,
        array $arguments = [],
        bool $suppressErrors = true
    ): string {
        if (PHP_OS_FAMILY === 'Windows') {
            $quotePath = function ($path) {
                $normalized = str_replace('/', '\\', $path);
                if (strpos($normalized, '"') === 0 && substr($normalized, -1) === '"') {
                    return $normalized;
                }

                return '"'.$normalized.'"';
            };

            $pythonCmd = file_exists($pythonCommand)
                ? $quotePath($pythonCommand)
                : $pythonCommand;
            $scriptQuoted = $quotePath($scriptPath);

            $args = array_map('escapeshellarg', $arguments);
            $errorRedirect = $suppressErrors ? '2>nul' : '';

            return sprintf(
                '%s %s %s %s',
                $pythonCmd,
                $scriptQuoted,
                implode(' ', $args),
                $errorRedirect
            );
        } else {
            $args = array_map('escapeshellarg', $arguments);
            $errorRedirect = $suppressErrors ? '2>/dev/null' : '';
            $encoding = 'PYTHONIOENCODING=utf-8 ';

            return sprintf(
                '%s%s %s %s %s',
                $encoding,
                escapeshellcmd($pythonCommand),
                escapeshellarg($scriptPath),
                implode(' ', $args),
                $errorRedirect
            );
        }
    }
}
