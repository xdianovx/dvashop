<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\LegalDocumentCode;
use App\Http\Controllers\Controller;
use App\Services\Storefront\LegalDocumentViewDataProvider;
use Illuminate\View\View;

final class LegalDocumentController extends Controller
{
    public function __construct(private readonly LegalDocumentViewDataProvider $documents) {}

    public function privacyPolicy(): View
    {
        return $this->show(LegalDocumentCode::PrivacyPolicy);
    }

    public function saleRules(): View
    {
        return $this->show(LegalDocumentCode::SaleRules);
    }

    public function returnsExchange(): View
    {
        return $this->show(LegalDocumentCode::ReturnsExchange);
    }

    public function informationUsageRules(): View
    {
        return $this->show(LegalDocumentCode::InformationUsageRules);
    }

    private function show(LegalDocumentCode $code): View
    {
        return view('legal-document', [
            'pageData' => $this->documents->load($code),
        ]);
    }
}
