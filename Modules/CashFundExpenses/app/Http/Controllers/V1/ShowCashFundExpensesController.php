<?php

namespace Modules\CashFundExpenses\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\CashFundExpenses\Http\Requests\ShowCashFundExpensesRequest;
use Modules\CashFundExpenses\Http\Resources\CashFundExpensesResource;
use Modules\CashFundExpenses\Workflows\ShowCashFundExpensesWorkflow;

class ShowCashFundExpensesController extends Controller
{
    public function __invoke(
        ShowCashFundExpensesRequest $request,
        ShowCashFundExpensesWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.cash_fund_expenses_fetched_successfully'),
            new CashFundExpensesResource($workflow->handle($request->month(), $request->year())),
        );
    }
}
