<?php

namespace App\Enums;

enum LastSeenVisibility: string
{
    case Everyone = 'everyone';
    case Contacts = 'contacts';
    case Nobody = 'nobody';
}
