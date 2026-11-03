<?php

namespace App\Enums;

enum ContactRequestPolicy: string
{
    case Everyone = 'everyone';
    case Contacts = 'contacts';
    case Nobody = 'nobody';
}
