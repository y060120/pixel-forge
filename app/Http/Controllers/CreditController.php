<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CreditController extends Controller
{
    /**
     * Simulate buying credits to top up account balance.
     */
    public function buy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000'],
        ]);

        $amount = number_format((float) $validated['amount'], 4, '.', '');
        $user = $request->user();

        $user->addCredits(
            $amount,
            'tx_'.Str::lower(Str::random(12)),
            "Simulated credit purchase: \${$amount}"
        );

        return back()->with('success', "Added \${$amount} credits to your account!");
    }
}
