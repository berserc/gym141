<?php

declare(strict_types=1);

namespace App\Core;

/** Einmal-Meldungen und Formularwerte ueber einen Redirect hinweg. */
final class Flash
{
    public static function add(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('error', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    /** @return list<array{type:string,message:string}> */
    public static function take(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        /** @var list<array{type:string,message:string}> */
        return $messages;
    }

    /**
     * Merkt Formulareingaben und Feldfehler fuer die naechste Anfrage.
     *
     * @param array<string,mixed>  $input
     * @param array<string,string> $errors
     */
    public static function withInput(array $input, array $errors = []): void
    {
        unset($input['password'], $input['password_confirm'], $input['csrf_token']);

        $_SESSION['_old']    = $input;
        $_SESSION['_errors'] = $errors;
    }

    /** @return array<string,mixed> */
    public static function oldInput(): array
    {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);

        /** @var array<string,mixed> */
        return $old;
    }

    /** @return array<string,string> */
    public static function errors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);

        /** @var array<string,string> */
        return $errors;
    }
}
