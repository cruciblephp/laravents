<?php

declare(strict_types=1);

namespace Modules\Band\Api;

/** What a member plays: part of the Band's Api, so every module may use it. */
enum Instrument: string
{
    case Guitar = 'guitar';
    case Bass = 'bass';
    case Drums = 'drums';
    case Keys = 'keys';
}
