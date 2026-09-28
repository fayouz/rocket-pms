<?php

namespace App\Mailer;

/** The shared inbox cannot be read (none configured, or Rocket Mailer refuses it to the application): e-mails are simply not shown. */
final class InboxUnavailable extends \RuntimeException
{
}
