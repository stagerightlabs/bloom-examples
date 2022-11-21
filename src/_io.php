<?php

class IO
{
    const COLOR_DEFAULT = 39;
    const COLOR_BLACK = 30;
    const COLOR_RED = 31;
    const COLOR_GREEN = 32;
    const COLOR_YELLOW = 33;
    const COLOR_BLUE = 34;
    const COLOR_MAGENTA = 35;
    const COLOR_CYAN = 36;
    const COLOR_LIGHT_GRAY = 37;

    /**
     * Prompt the user to enter a value.
     *
     * @param string $question
     * @return string
     */
    public static function prompt(string $question): string
    {
        self::print($question);
        $handle = fopen("php://stdin", "rb");
        $line = fgets($handle);
        fclose($handle);
        return trim($line);
    }

    /**
     * Prompt the user to return a boolean "yes" or "no".
     *
     * @param string $question
     * @return bool
     */
    public static function confirm(string $question): bool
    {
        self::print($question . ' (y/n)');
        $handle = fopen("php://stdin", "rb");
        $line = fgets($handle);
        fclose($handle);
        return strtoupper(trim($line)) == 'Y';
    }

    /**
     * Print a line to the console.
     *
     * @param string $line
     * @return void
     */
    public static function print(string $line)
    {
        echo $line . "\n";
    }

    /**
     * Wrap a string with escape codes for a specified color.
     *
     * @param string $str
     * @param int $code
     * @return string
     */
    public static function color(string $str, int $code): string
    {
        if (self::wantsColorCodes()) {
            return "\033[{$code}m{$str}\033[0m";
        }

        return $str;
    }

    /**
     * Print an 'informational' line to the console.
     *
     * @param string $line
     * @return void
     */
    public static function info(string $line)
    {
        echo self::color($line, self::COLOR_BLUE) . "\n";
    }

    /**
     * Print an 'error' line to the console.
     *
     * @param string $line
     * @return void
     */
    public static function error(string $line)
    {
        echo self::color($line, self::COLOR_RED) . "\n";
    }

    /**
     * Print a 'success' line to the console.
     *
     * @param string $line
     * @return void
     */
    public static function success(string $line)
    {
        echo self::color($line, self::COLOR_GREEN) . "\n";
    }

    /**
     * Print a 'warning' line to the console.
     *
     * @param string $line
     * @return void
     */
    public static function warning(string $line)
    {
        echo self::color($line, self::COLOR_YELLOW) . "\n";
    }

    /**
     * Print all the color options to the screen.
     *
     * @return void
     */
    public static function rainbow()
    {
        self::print(self::color('default', self::COLOR_DEFAULT));
        self::print(self::color('black', self::COLOR_BLACK));
        self::print(self::color('red', self::COLOR_RED));
        self::print(self::color('green', self::COLOR_GREEN));
        self::print(self::color('yellow', self::COLOR_YELLOW));
        self::print(self::color('blue', self::COLOR_BLUE));
        self::print(self::color('magenta', self::COLOR_MAGENTA));
        self::print(self::color('cyan', self::COLOR_CYAN));
        self::print(self::color('light gray', self::COLOR_LIGHT_GRAY));
    }

    /**
     * Does the current console interface support ANSI color output?
     *
     * @see https://github.com/symfony/console/blob/d1d8b8fd9b605630aa73b1b384e246fee54e8ffa/Output/StreamOutput.php#L94
     * @return bool
     */
    private static function wantsColorCodes(): bool
    {
        // Follow https://no-color.org/
        if (isset($_SERVER['NO_COLOR']) || false !== getenv('NO_COLOR')) {
            return false;
        }

        // Special consideration for Windows environments
        if (\DIRECTORY_SEPARATOR === '\\') {
            return (\function_exists('sapi_windows_vt100_support')
                && @sapi_windows_vt100_support(STDOUT))
                || false !== getenv('ANSICON')
                || 'ON' === getenv('ConEmuANSI')
                || 'xterm' === getenv('TERM');
        }

        return stream_isatty(STDOUT);
    }
}
