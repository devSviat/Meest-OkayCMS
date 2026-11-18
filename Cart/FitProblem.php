<?php

namespace Okay\Modules\Sviat\Meest\Cart;

enum FitProblem: string
{
    case TooHeavy = 'too_heavy';
    case TooBig = 'too_big';
    case OverInsurance = 'over_insurance';
    case Inbound = 'inbound';
}
