<?php

namespace A17\Twill\Repositories\Behaviors;

use A17\Twill\Facades\TwillUtil;
use A17\Twill\Services\Blocks\BlockCollection;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait HandleRepeaters
{
    /**
     * All repeaters used in the model, as an array of repeater names:
     * [
     *     'article_repeater',
     *     'page_repeater'
     * ].
     *
     * When only the repeater name is given, the model and relation are inferred from the name.
     * The parameters can also be overridden with an array:
     * [
     *     'article_repeater',
     *     'page_repeater' => [
     *         'model' => 'Page',
     *         'relation' => 'pages'
     *     ]
     * ]
     *
     * @var array
     */
    protected $repeaters = [];

    /**
     * @param \A17\Twill\Models\Model $object
     * @param array $fields
     * @return void
     */
    public function afterSaveHandleRepeaters($object, $fields)
    {
        foreach ($this->getRepeaters() as $repeater) {
            $this->updateRepeater(
                $object,
                $fields,
                $repeater['relation'],
                $repeater['model'],
                $repeater['repeaterName']
            );
        }
    }

    /**
     * @param \A17\Twill\Models\Model $object
     * @param array $fields
     * @return array
     */
    public function getFormFieldsHandleRepeaters($object, $fields)
    {
        foreach ($this->getRepeaters() as $repeater) {
            $fields = $this->getFormFieldsForRepeater(
                $object,
                $fields,
                $repeater['relation'],
                $repeater['model'],
                $repeater['repeaterName']
            );
        }

        return $fields;
    }

    /**
     * @param \A17\Twill\Models\Model $object
     * @param array $fields
     * @param string $relation
     * @param bool $keepExisting
     * @param \A17\Twill\Models\Model|null $model
     * @return void
     */
    public function updateRepeaterMany($object, $fields, $relation, $keepExisting = true, $model = null)
    {
        $relationFields = $fields['repeaters'][$relation] ?? [];
        $relationRepository = $this->getModelRepository($relation, $model);

        if (!$keepExisting) {
            $object->$relation()->each(function ($repeaterElement) {
                $repeaterElement->forceDelete();
            });
        }

        foreach ($relationFields as $relationField) {
            $newRelation = $relationRepository->create($relationField);
            $object->$relation()->attach($newRelation->id);
        }
    }

    /**
     * @param \A17\Twill\Models\Model $object
     * @param array $fields
     * @param string $relation
     * @param string|null $morph
     * @param \A17\Twill\Models\Model|null $model
     * @param string|null $repeaterName
     * @return void
     */
    public function updateRepeaterMorphMany(
        $object,
        $fields,
        $relation,
        $morph = null,
        $model = null,
        $repeaterName = null
    ) {
        if (!$repeaterName) {
            $repeaterName = $relation;
        }

        $relationFields = $fields['repeaters'][$repeaterName] ?? [];
        $relationRepository = $this->getModelRepository($relation, $model);

        $morph = $morph ?: $relation;

        $morphFieldType = $morph . '_type';
        $morphFieldId = $morph . '_id';

        // if no relation field submitted, soft deletes all associated rows
        if (!$relationFields) {
            $relationRepository->updateBasic(null, [
                'deleted_at' => Carbon::now(),
            ], [
                $morphFieldType => $object->getMorphClass(),
                $morphFieldId => $object->id,
            ]);
        }

        // keep a list of updated and new rows to delete (soft delete?) old rows that were deleted from the frontend
        $currentIdList = [];

        foreach ($relationFields as $index => $relationField) {
            $relationField['position'] = $index + 1;
            if (isset($relationField['id']) && Str::startsWith($relationField['id'], $relation)) {
                // row already exists, let's update
                $id = str_replace($relation . '-', '', $relationField['id']);
                $relationRepository->update($id, $relationField);
                $currentIdList[] = $id;
            } else {
                // new row, let's attach to our object and create
                unset($relationField['id']);
                $newRelation = $relationRepository->create($relationField);
                $object->$relation()->save($newRelation);
                $currentIdList[] = $newRelation['id'];
            }
        }

        foreach ($object->$relation->pluck('id') as $id) {
            if (!in_array($id, $currentIdList)) {
                $relationRepository->updateBasic(null, [
                    'deleted_at' => Carbon::now(),
                ], [
                    'id' => $id,
                ]);
            }
        }
    }

    /**
     * Given relation, model and repeaterName, retrieve the repeater data from request and update the database record.
     *
     * @param \A17\Twill\Models\Model $object
     * @param array $fields
     * @param string $relation
     * @param \A17\Twill\Models\Model|\A17\Twill\Repositories\ModuleRepository|null $modelOrRepository
     * @param string|null $repeaterName
     * @return void
     */
    public function updateRepeater($object, $fields, $relation, $modelOrRepository = null, $repeaterName = null)
    {
        if (!$repeaterName) {
            $repeaterName = $relation;
        }

        $relationFields = $fields['repeaters'][$repeaterName] ?? [];

        $relationRepository = $this->getModelRepository($relation, $modelOrRepository);

        // if no relation field submitted, soft deletes all associated rows
        if (!$relationFields) {
            $relationRepository->updateBasic(null, [
                'deleted_at' => Carbon::now(),
            ], [
                $this->model->getForeignKey() => $object->id,
            ]);
        }

        // keep a list of updated and new rows to delete (soft delete?) old rows that were deleted from the frontend
        $currentIdList = [];

        foreach ($relationFields as $index => $relationField) {
            $relationField['position'] = $index + 1;
            // If the relation is not an "existing" one try to match it with our session.
            if (
                !Str::startsWith($relationField['id'], $relation) &&
                $id = TwillUtil::hasRepeaterIdFor($relationField['id'])
            ) {
                $relationField['id'] = $relation . '-' . $id;
            }

            // Set the active data based on the parent.
            if (!isset($relationField['languages']) && isset($relationField['active'])) {
                foreach ($relationField['active'] as $langCode => $active) {
                    // Add the languages field.
                    $relationField['languages'][] = [
                        'value' => $langCode,
                        'published' => $fields[$langCode]['active']
                    ];
                }
            }

            // Finally store the data.
            if (isset($relationField['id']) && Str::startsWith($relationField['id'], $relation)) {
                // row already exists, let's update
                $id = str_replace($relation . '-', '', $relationField['id']);
                $relationRepository->update($id, $relationField);
                $currentIdList[] = $id;
            } else {
                // new row, let's attach to our object and create
                $relationField[$this->model->getForeignKey()] = $object->id;
                $frontEndId = $relationField['id'];
                unset($relationField['id']);
                $newRelation = $relationRepository->create($relationField);
                $currentIdList[] = $newRelation['id'];

                TwillUtil::registerRepeaterId($frontEndId, $newRelation->id);
            }
        }

        foreach ($object->$relation->pluck('id') as $id) {
            if (!in_array($id, $currentIdList)) {
                $relationRepository->updateBasic(null, [
                    'deleted_at' => Carbon::now(),
                ], [
                    'id' => $id,
                ]);
            }
        }
    }

    /**
     * Given relation, model and repeaterName, get the necessary fields for rendering a repeater
     *
     * @param \A17\Twill\Models\Model $object
     * @param array $fields
     * @param string $relation
     * @param \A17\Twill\Models\Model|\A17\Twill\Repositories\ModuleRepository|null $modelOrRepository
     * @param string|null $repeaterName
     * @return array
     */
    public function getFormFieldsForRepeater(
        $object,
        $fields,
        $relation,
        $modelOrRepository = null,
        $repeaterName = null
    ) {
        if (!$repeaterName) {
            $repeaterName = $relation;
        }

        $repeaters = [];
        $repeatersFields = [];
        $repeatersBrowsers = [];
        $repeatersMedias = [];
        $repeatersFiles = [];
        $relationRepository = $this->getModelRepository($relation, $modelOrRepository);
        $repeatersList = app(BlockCollection::class)->getRepeaterList()->keyBy('name');

        foreach ($object->$relation as $relationItem) {
            $repeaters[] = [
                'id' => $relation . '-' . $relationItem->id,
                'type' => $repeatersList[$repeaterName]['component'],
                'title' => $repeatersList[$repeaterName]['title'],
                'titleField' => $repeatersList[$repeaterName]['titleField'],
                'hideTitlePrefix' => $repeatersList[$repeaterName]['hideTitlePrefix'],
            ];

            $relatedItemFormFields = $relationRepository->getFormFields($relationItem);
            $translatedFields = [];

            if (isset($relatedItemFormFields['translations'])) {
                foreach ($relatedItemFormFields['translations'] as $key => $values) {
                    $repeatersFields[] = [
                        'name' => "blocks[$relation-$relationItem->id][$key]",
                        'value' => $values,
                    ];

                    $translatedFields[] = $key;
                }
            }

            if (isset($relatedItemFormFields['medias'])) {
                if (config('twill.media_library.translated_form_fields', false)) {
                    Collection::make($relatedItemFormFields['medias'])->each(
                        function ($rolesWithMedias, $locale) use (&$repeatersMedias, $relation, $relationItem) {
                            $repeatersMedias[] = Collection::make($rolesWithMedias)->mapWithKeys(
                                function ($medias, $role) use ($locale, $relation, $relationItem) {
                                    return [
                                        "blocks[$relation-$relationItem->id][$role][$locale]" => $medias,
                                    ];
                                }
                            )->toArray();
                        }
                    );
                } else {
                    foreach ($relatedItemFormFields['medias'] as $key => $values) {
                        $repeatersMedias["blocks[$relation-$relationItem->id][$key]"] = $values;
                    }
                }
            }

            if (isset($relatedItemFormFields['files'])) {
                Collection::make($relatedItemFormFields['files'])->each(
                    function ($rolesWithFiles, $locale) use (&$repeatersFiles, $relation, $relationItem) {
                        $repeatersFiles[] = Collection::make($rolesWithFiles)->mapWithKeys(
                            function ($files, $role) use ($locale, $relation, $relationItem) {
                                return [
                                    "blocks[$relation-$relationItem->id][$role][$locale]" => $files,
                                ];
                            }
                        )->toArray();
                    }
                );
            }

            if (isset($relatedItemFormFields['browsers'])) {
                foreach ($relatedItemFormFields['browsers'] as $key => $values) {
                    $repeatersBrowsers["blocks[$relation-$relationItem->id][$key]"] = $values;
                }
            }

            $itemFields = method_exists($relationItem, 'toRepeaterArray') ? $relationItem->toRepeaterArray(
            ) : Arr::except($relationItem->attributesToArray(), $translatedFields);

            foreach ($itemFields as $key => $value) {
                $repeatersFields[] = [
                    'name' => "blocks[$relation-$relationItem->id][$key]",
                    'value' => $value,
                ];
            }

            if (isset($relatedItemFormFields['repeaters'])) {
                foreach ($relatedItemFormFields['repeaters'] as $childRepeaterName => $childRepeaterItems) {
                    $fields['repeaters']["blocks-$relation-{$relationItem->id}_$childRepeaterName"] = $childRepeaterItems;
                    $repeatersFields = array_merge(
                        $repeatersFields,
                        $relatedItemFormFields['repeaterFields'][$childRepeaterName]
                    );
                    $repeatersMedias = array_merge(
                        $repeatersMedias,
                        $relatedItemFormFields['repeaterMedias'][$childRepeaterName]
                    );
                    $repeatersFiles = array_merge(
                        $repeatersFiles,
                        $relatedItemFormFields['repeaterFiles'][$childRepeaterName]
                    );
                    $repeatersBrowsers = array_merge(
                        $repeatersBrowsers,
                        $relatedItemFormFields['repeaterBrowsers'][$childRepeaterName]
                    );
                }
            }
        }

        if (!empty($repeatersMedias) && config('twill.media_library.translated_form_fields', false)) {
            $repeatersMedias = call_user_func_array('array_merge', $repeatersMedias);
        }

        if (!empty($repeatersFiles)) {
            $repeatersFiles = call_user_func_array('array_merge', $repeatersFiles);
        }

        $fields['repeaters'][$repeaterName] = $repeaters;
        $fields['repeaterFields'][$repeaterName] = $repeatersFields;
        $fields['repeaterMedias'][$repeaterName] = $repeatersMedias;
        $fields['repeaterFiles'][$repeaterName] = $repeatersFiles;
        $fields['repeaterBrowsers'][$repeaterName] = $repeatersBrowsers;

        return $fields;
    }

    /**
     * Get all repeaters' model and relation from the $repeaters attribute.
     * The missing information will be inferred by convention of Twill.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getRepeaters()
    {
        return collect($this->repeaters)->map(function ($repeater, $key) {
            $repeaterName = is_string($repeater) ? $repeater : $key;
            return [
                'relation' => !empty($repeater['relation']) ? $repeater['relation'] : $this->inferRelationFromRepeaterName(
                    $repeaterName
                ),
                'model' => !empty($repeater['model']) ? $repeater['model'] : $this->inferModelFromRepeaterName(
                    $repeaterName
                ),
                'repeaterName' => $repeaterName,
            ];
        })->values();
    }

    /**
     * Guess the relation name (shoud be lower camel case, ex. userGroup, contactOffice).
     *
     * @param string $repeaterName
     * @return string
     */
    protected function inferRelationFromRepeaterName(string $repeaterName): string
    {
        return Str::camel($repeaterName);
    }

    /**
     * Guess the model name (should be singular upper camel case, ex. User, ArticleType).
     *
     * @param string $repeaterName
     * @return string
     */
    protected function inferModelFromRepeaterName(string $repeaterName): string
    {
        return Str::studly(Str::singular($repeaterName));
    }
}
