<?php

namespace Modules\User\app\Http\Controllers\V1;


use App\Http\Controllers\Controller;
use App\Support\Query\BaseQueryService;
use Illuminate\Http\Request;
use Modules\User\app\Actions\ListUserAction;

class UserController extends Controller
{
  public function __construct(private ListUserAction $listUserAction) {}

  public function __invoke(Request $request)
  {
    return $this->listUserAction->execute($request, $this);
  }
}