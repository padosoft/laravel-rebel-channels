<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Enums;

/**
 * A delivery channel a verification/message can travel over.
 */
enum Channel: string
{
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Voice = 'voice';
    case Telegram = 'telegram';
    case Discord = 'discord';
}
