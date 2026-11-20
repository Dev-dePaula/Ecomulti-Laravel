<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;

/**
 * Envio de e-mails da área do cliente e do formulário de contacto.
 * Usa o SMTP do Laravel (MAIL_*). Em desenvolvimento (MAIL_MAILER=log) as mensagens ficam em storage/logs.
 */
class Email
{
    public static function disponivel(): bool
    {
        $m = (string) config('mail.default');
        if (in_array($m, ['log', 'array'], true)) {
            return true;
        }
        if ($m === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host');

            return $host !== '' && $host !== '127.0.0.1' && $host !== 'localhost';
        }

        return $m !== '';
    }

    public static function enviar(string $para, string $assunto, string $texto, ?string $html = null, ?string $responderPara = null): void
    {
        Mail::send([], [], function ($m) use ($para, $assunto, $texto, $html, $responderPara) {
            $m->to($para)->subject($assunto);
            $e = $m->getSymfonyMessage();
            $e->text($texto);
            if ($html) {
                $e->html($html);
            }
            if ($responderPara) {
                $m->replyTo($responderPara);
            }
        });
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** e-mail com um código de 6 dígitos (confirmar conta / recuperar senha) */
    public static function codigo(string $nome, string $codigo, string $motivo): array
    {
        $titulo = $motivo === 'recuperar' ? 'Recuperar a senha' : 'Confirmar o seu e-mail';
        $frase = $motivo === 'recuperar'
            ? 'Recebemos um pedido para definir uma nova senha na sua área de cliente ECOMULTI.'
            : 'Obrigado por criar a sua conta na área de cliente ECOMULTI.';
        $ola = $nome !== '' ? " $nome" : '';
        $texto = "Olá{$ola},\n\n{$frase}\n\nO seu código é: {$codigo}\n\nO código é válido durante 30 minutos. "
            ."Se não foi você que fez este pedido, ignore este e-mail.\n\nECOMULTI — www.ecomulti.ao";
        $e = fn ($s) => self::esc($s);
        $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#2b3555">'
            .'<h2 style="color:#0a1747">'.$e($titulo).'</h2><p>Olá'.($nome !== '' ? ' '.$e($nome) : '').',</p><p>'.$e($frase).'</p>'
            .'<p style="font-size:28px;font-weight:bold;letter-spacing:6px;color:#2a50d8;margin:24px 0">'.$e($codigo).'</p>'
            .'<p>O código é válido durante 30 minutos. Se não foi você que fez este pedido, ignore este e-mail.</p>'
            .'<p style="color:#5f6a8a;font-size:13px">ECOMULTI — <a href="https://www.ecomulti.ao">www.ecomulti.ao</a></p></div>';

        return ['assunto' => "ECOMULTI — {$titulo}: {$codigo}", 'texto' => $texto, 'html' => $html];
    }
}
