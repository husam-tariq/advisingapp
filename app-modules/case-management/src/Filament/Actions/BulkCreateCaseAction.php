<?php

/*
<COPYRIGHT>

    Copyright © 2016-2025, Canyon GBS LLC. All rights reserved.

    Advising App™ is licensed under the Elastic License 2.0. For more details,
    see https://github.com/canyongbs/advisingapp/blob/main/LICENSE.

    Notice:

    - You may not provide the software to third parties as a hosted or managed
      service, where the service provides users with access to any substantial set of
      the features or functionality of the software.
    - You may not move, change, disable, or circumvent the license key functionality
      in the software, and you may not remove or obscure any functionality in the
      software that is protected by the license key.
    - You may not alter, remove, or obscure any licensing, copyright, or other notices
      of the licensor in the software. Any use of the licensor’s trademarks is subject
      to applicable law.
    - Canyon GBS LLC respects the intellectual property rights of others and expects the
      same in return. Canyon GBS™ and Advising App™ are registered trademarks of
      Canyon GBS LLC, and we are committed to enforcing and protecting our trademarks
      vigorously.
    - The software solution, including services, infrastructure, and code, is offered as a
      Software as a Service (SaaS) by Canyon GBS LLC.
    - Use of this software implies agreement to the license terms and conditions as stated
      in the Elastic License 2.0.

    For more information or inquiries please visit our website at
    https://www.canyongbs.com or contact us via email at legal@canyongbs.com.

</COPYRIGHT>
*/

namespace AdvisingApp\CaseManagement\Filament\Actions;

use AdvisingApp\CaseManagement\Enums\CaseAssignmentStatus;
use AdvisingApp\CaseManagement\Models\CaseModel;
use AdvisingApp\CaseManagement\Models\CasePriority;
use AdvisingApp\CaseManagement\Models\CaseStatus;
use AdvisingApp\Division\Models\Division;
use AdvisingApp\Prospect\Models\Prospect;
use AdvisingApp\StudentDataModel\Models\Student;
use App\Models\User;
use Exception;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BulkCreateCaseAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('createCase')
            ->label('Open Case')
            ->icon('heroicon-o-folder-open')
            ->modalHeading('Create Case')
            ->form([
                Select::make('division_id')
                    ->relationship('division', 'name')
                    ->model(CaseModel::class)
                    ->default(
                        fn () => auth()->user()->team?->division?->getKey()
                               ?? Division::query()
                                   ->where('is_default', true)
                                   ->first()
                                   ?->getKey()
                    )
                    ->label('Division')
                    ->visible(function () {
                        return Division::query()->where('is_default', false)->exists();
                    })
                    ->dehydratedWhenHidden()
                    ->required()
                    ->exists((new Division())->getTable(), 'id'),
                Select::make('status_id')
                    ->relationship('status', 'name')
                    ->model(CaseModel::class)
                    ->preload()
                    ->label('Status')
                    ->required()
                    ->exists((new CaseStatus())->getTable(), 'id'),
                Select::make('priority_id')
                    ->relationship(
                        name: 'priority',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->orderBy('order'),
                    )
                    ->model(CaseModel::class)
                    ->label('Priority')
                    ->required()
                    ->exists((new CasePriority())->getTable(), 'id'),
                Select::make('assigned_to_id')
                    ->relationship('assignedTo.user', 'name')
                    ->model(CaseModel::class)
                    ->searchable()
                    ->label('Assign Case to')
                    ->nullable()
                    ->exists((new User())->getTable(), 'id'),
                Textarea::make('close_details')
                    ->label('Close Details/Description')
                    ->nullable()
                    ->string(),
                Textarea::make('res_details')
                    ->label('Internal Case Details')
                    ->nullable()
                    ->string(),
            ])
            ->action(function (Collection $records, array $data) {
                try {
                    DB::beginTransaction();

                    $records->each(function ($record) use ($data) {
                        throw_unless($record instanceof Student || $record instanceof Prospect, new Exception('Record must be of type student or prospect.'));
                        $case = $record->cases()->create([
                            'close_details' => $data['close_details'],
                            'res_details' => $data['res_details'],
                            'division_id' => $data['division_id'],
                            'status_id' => $data['status_id'],
                            'priority_id' => $data['priority_id'],
                            'created_by_id' => auth()->user()->getKey(),
                        ]);

                        if (isset($data['assigned_to_id'])) {
                            $case->assignments()->create([
                                'user_id' => $data['assigned_to_id'],
                                'assigned_by_id' => auth()->user()->getKey(),
                                'assigned_at' => now(),
                                'status' => CaseAssignmentStatus::Active,
                            ]);
                        }
                    });

                    DB::commit();
                } catch (Exception $e) {
                    report($e);
                    DB::rollBack();
                    Notification::make()
                        ->title('Something went wrong')
                        ->body('We failed to create the ' . Str::plural('case', $records) . '. Please try again later.')
                        ->danger()
                        ->send();

                    return;
                }
                Notification::make()
                    ->title(Str::plural('Case', $records) . ' created')
                    ->body('The ' . Str::plural('case', $records) . ' have been created with your selections.')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
