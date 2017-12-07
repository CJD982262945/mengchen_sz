<?php
/**
 * Created by PhpStorm.
 * User: liudian
 * Date: 9/5/17
 * Time: 14:46
 */

namespace App\Http\Controllers\Admin;

use App\Exceptions\CustomException;
use App\Http\Controllers\Controller;
use App\Models\OperationLogs;
use App\Models\TopUpAdmin;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\AdminRequest;
use App\Models\StockApply;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    protected $per_page = 15;
    protected $order = ['id', 'desc'];

    public function __construct(Request $request)
    {
        $this->per_page = $request->per_page ?: $this->per_page;
        $this->order = $request->sort ? explode('|', $request->sort) : $this->order;
    }

    /**
     * 管理员提交库存申请
     *
     * @param Request $request
     * @return array
     */
    public function apply(AdminRequest $request)
    {
        $data = $this->validateApply($request);
        $data = array_merge($data, ['applicant_id' => $request->user()->id]);

        StockApply::create($data);

        OperationLogs::add($request->user()->id, $request->path(), $request->method(),
            '管理员申请库存', $request->header('User-Agent'), json_encode($data));

        return [
            'message' => '提交申请成功'
        ];
    }

    protected function validateApply($request)
    {
        Validator::make($request->all(), [
            'item_id' => 'required|integer|exists:item_type,id',
            'amount' => 'required|integer',
            'remark' => 'nullable|string|max:255'
        ])->validate();

        return $request->intersect(
            'item_id', 'amount', 'remark'
        );
    }

    /**
     * 查看所有待审核的库存申请
     *
     * @param Request $request
     * @return null
     */
    public function applyList(AdminRequest $request)
    {
        OperationLogs::add($request->user()->id, $request->path(), $request->method(),
            '管理员查看库存申请列表', $request->header('User-Agent'), json_encode($request->all()));

        //搜索代理商
        if ($request->has('filter')) {
            $applicants = array_column(User::where('account', 'like', "%{$request->filter}%")->get()->toArray(), 'id');
            if (empty($applicants)) {
                return null;
            }
            return  StockApply::with(['applicant.group', 'approver', 'item'])
                ->whereIn('applicant_id', $applicants)
                ->applyList()
                ->orderBy($this->order[0], $this->order[1])
                ->paginate($this->per_page);
        }

        return StockApply::with(['applicant.group', 'approver', 'item'])
            ->applyList()
            ->orderBy($this->order[0], $this->order[1])
            ->paginate($this->per_page);
    }

    /**
     * 同意库存申请，扣除库存，新添库存
     *
     * @param Request $request
     * @param StockApply $entry
     * @return array
     */
    public function approve(AdminRequest $request, StockApply $entry)
    {
        Validator::make($request->all(), [
            'approver_remark' => 'nullable|string|max:255'
        ])->validate();

        $approver = User::with(['inventory' => function ($query) use ($entry) {
            $query->where('item_id', $entry->item_id);
        }])->find($request->user()->id);
        $applicant = User::with(['inventory' => function ($query) use ($entry) {
            $query->where('item_id', $entry->item_id);
        }])->find($entry->applicant_id);

        if ($applicant->isNotValidStockApplicant()) {
            throw new CustomException('审批失败，只能审批管理员和总代提交的申请');
        }

        if (! $this->checkStock($approver, $applicant, $entry->amount)) {
            throw new CustomException('库存不足，无法审批');
        }

        $this->doApprove($approver, $applicant, $entry, $request->remark);

        OperationLogs::add($request->user()->id, $request->path(), $request->method(),
            '管理员同意库存申请', $request->header('User-Agent'), $entry->toJson());

        return [
            'message' => '审核通过',
        ];
    }

    protected function checkStock($approver, $applicant, $amount)
    {
        //如果是管理员自己申请的，就不检查库存情况
        return $applicant->is_admin or (!empty($approver->inventory)) and $approver->inventory->stock >= $amount;
    }

    protected function doApprove($approver, $applicant, $entry, $remark = '')
    {
        DB::transaction(function () use ($approver, $applicant, $entry, $remark) {
            //更新审核状态
            $entry->update([
                'state' => 2,
                'approver_id' => $approver->id,
                'approver_remark' => $remark
            ]);

            //添加充值记录
            $this->addTopUpHistory($approver, $entry);

            //更新申请者的库存
            if (empty($applicant->inventory)) {
                $applicant->inventory()->create([
                    'user_id' => $applicant->id,
                    'item_id' => $entry->item_id,
                    'stock' => $entry->amount,
                ]);
            } else {
                $totalStock = $entry->amount + $applicant->inventory->stock;
                $applicant->inventory->update([
                    'stock' => $totalStock,
                ]);
            }

            //如果申请者是管理员自己，不减库存
            if ($applicant->is_admin) {
                return true;
            }

            //减审批者（管理员）的库存
            $leftStock = $approver->inventory->stock - $entry->amount;
            $approver->inventory->update([
                'stock' => $leftStock,
            ]);
        });
    }

    protected function addTopUpHistory($approver, $entry)
    {
        TopUpAdmin::create([
            'provider_id' => $approver->id,
            'receiver_id' => $entry->applicant_id,
            'type' => $entry->item_id,
            'amount' => $entry->amount,
        ]);
    }

    /**
     * 拒绝库存申请
     *
     * @param Request $request
     * @param StockApply $entry
     * @return array
     */
    public function decline(AdminRequest $request, StockApply $entry)
    {
        Validator::make($request->all(), [
            'approver_remark' => 'nullable|string|max:255'
        ])->validate();

        $entry->update([
            'state' => 3,
            'approver_id' => $request->user()->id,
            'approver_remark' => $request->approver_remark,
        ]);

        OperationLogs::add($request->user()->id, $request->path(), $request->method(),
            '管理员拒绝库存申请', $request->header('User-Agent'), $entry->toJson());

        return [
            'message' => '操作成功',
        ];
    }

    /**
     * 查看审核历史
     *
     * @param Request $request
     * @return null
     */
    public function applyHistory(AdminRequest $request)
    {
        OperationLogs::add($request->user()->id, $request->path(), $request->method(),
            '管理员查看审核历史', $request->header('User-Agent'));

        //搜索申请人账号
        if ($request->has('filter')) {
            $applicants = array_column(User::where('account', 'like', "%{$request->filter}%")->get()->toArray(), 'id');
            if (empty($applicants)) {
                return null;
            }
            return StockApply::with(['applicant', 'approver', 'item'])
                ->whereIn('applicant_id', $applicants)
                ->applyHistory()
                ->orderBy($this->order[0], $this->order[1])
                ->paginate($this->per_page);
        }

        return StockApply::with(['applicant', 'approver', 'item'])
            ->applyHistory()
            ->orderBy($this->order[0], $this->order[1])
            ->paginate($this->per_page);
    }
}