<?php declare(strict_types=1);

namespace Plugin\CommerceML\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Service\Catalog\AttributeService as CatalogAttributeService;
use App\Domain\Service\Catalog\CategoryService as CatalogCatalogService;
use App\Domain\Service\Catalog\Exception\AttributeNotFoundException;
use App\Domain\Service\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Service\Catalog\Exception\ProductNotFoundException;
use App\Domain\Service\Catalog\ProductAttributeService as CatalogProductAttributeService;
use App\Domain\Service\Catalog\ProductService as CatalogProductService;
use App\Domain\Service\File\FileRelationService;
use App\Domain\Service\File\FileService;
use XMLReader;

class ImportCMLTask extends AbstractTask
{
    public const TITLE = 'Импорт из Commerce ML';

    public function execute(array $params = []): \App\Domain\Entities\Task
    {
        $default = [
            'files' => [],
        ];
        $params = array_merge($default, $params);

        return parent::execute($params);
    }

    protected function action(array $args = [])
    {
        $fileService = FileService::getWithContainer($this->container);
        $files = $fileService->read([
            'name' => $args['files'],
            'order' => ['date' => 'ASC'],
        ]);

        $data = [
            'categories' => [],
            'properties' => [],
            'prices' => [],
            'products' => [],
        ];

        /** @var \App\Domain\Entities\File $file */
        foreach ($files as $index => $file) {
            $reader = new XMLReader();
            $reader->open($file->getInternalPath());
            $xml = $this->parseXml($reader);

            if (isset($xml['КоммерческаяИнформация'][0])) {
                $xml = $xml['КоммерческаяИнформация'][0];

                switch (true) {
                    case isset($xml['Классификатор'][0]['Группы']) && !$data['categories']:
                        $data['categories'] = $this->parseCategories($xml['Классификатор'][0]);
                        break;

                    case isset($xml['Классификатор'][0]['Свойства']) && !$data['properties']:
                        $data['properties'] = $this->parseProperties($xml['Классификатор'][0]);
                        break;

                    case isset($xml['Каталог'][0]['Товары']) && !$data['products']:
                        $data['products'] = $this->parseProducts($xml['Каталог'][0]);
                        break;
                }
            }

            $this->setProgress($index, count($files));
        }

        if ($data['categories'] && $data['products']) {
            $fileRelationService = FileRelationService::getWithContainer($this->container);
            $catalogCategoryService = CatalogCatalogService::getWithContainer($this->container);
            $catalogProductService = CatalogProductService::getWithContainer($this->container);
            $catalogAttributeService = CatalogAttributeService::getWithContainer($this->container);
            $catalogProductAttributeService = CatalogProductAttributeService::getWithContainer($this->container);

            // подготовка свойств
            foreach ($data['properties'] as $index => &$datum) {
                try {
                    $datum['attribute'] = $catalogAttributeService->read(['title' => $datum['title']]);
                } catch (AttributeNotFoundException $e) {
                    $datum['attribute'] = $catalogAttributeService->create([
                        'title' => $datum['title'],
                        'type' => \App\Domain\Types\Catalog\AttributeTypeType::TYPE_STRING,
                    ]);
                }

                $this->setProgress($index, count($files));
            }

            // saved parameters of view for category and products
            $template = [
                'category' => $this->parameter('catalog_category_template', 'catalog.category.twig'),
                'product' => $this->parameter('catalog_product_template', 'catalog.product.twig'),
            ];
            $pagination = $this->parameter('catalog_category_pagination', 10);

            // обработка категорий
            foreach ($data['categories'] as $index => &$datum) {
                $create = false;
                try {
                    $parent = $catalogCategoryService->read(['external_id' => $datum['parent']])->getUuid();
                } catch (CategoryNotFoundException $e) {
                    $parent = \Ramsey\Uuid\Uuid::NIL;
                }
                try {
                    $datum['category'] = $catalogCategoryService->read(['external_id' => $datum['external_id']]);
                } catch (CategoryNotFoundException $e) {
                    $datum['category'] = $catalogCategoryService->create([
                        'title' => $datum['title'],
                        'parent' => $parent,
                        'external_id' => $datum['external_id'],
                        'template' => $template,
                        'pagination' => $pagination,
                        'export' => '1c',
                    ]);
                    $create = true;
                } finally {
                    if ($datum['category']) {
                        if (!$create) {
                            $catalogCategoryService->update($datum['category'], [
                                'title' => $datum['title'],
                                'parent' => $parent,
                                'status' => \App\Domain\Types\Catalog\CategoryStatusType::STATUS_WORK,
                            ]);
                        }
                    }
                }

                $this->setProgress($index, count($files));
            }

            // обработка продуктов
            foreach ($data['products'] as $index => $datum) {
                $create = false;
                $category = $catalogCategoryService->read(['external_id' => $datum['category']])->getUuid();

                try {
                    $datum['product'] = $catalogProductService->read(['external_id' => $datum['external_id']]);
                } catch (ProductNotFoundException $e) {
                    $datum['product'] = $catalogProductService->create([
                        'category' => $category,
                        'title' => $datum['title'],
                        'description' => $datum['description'],
                        'vendorcode' => $datum['vendorcode'],
                        'barcode' => $datum['barcode'],
                        'priceFirst' => 0.0,
                        'price' => 0.0,
                        'priceWholesale' => 0.0,
                        'volume' => $datum['volume'],
                        'unit' => $datum['unit'],
                        'external_id' => $datum['external_id'],
                        'export' => '1c',
                    ]);
                    $create = true;
                } finally {
                    if ($datum['product']) {
                        if (!$create) {
                            $catalogProductService->update($datum['product'], [
                                'category' => $category,
                                'title' => $datum['title'],
                                'description' => $datum['description'],
                                'vendorcode' => $datum['vendorcode'],
                                'barcode' => $datum['barcode'],
                                'priceFirst' => 0.0,
                                'price' => 0.0,
                                'priceWholesale' => 0.0,
                                'volume' => $datum['volume'],
                                'unit' => $datum['unit'],
                                'status' => \App\Domain\Types\Catalog\ProductStatusType::STATUS_WORK,
                            ]);
                        }

                        // запись новых свойств (атрибутов)
                        if ($datum['properties']) {
                            $attributes = [];
                            $properties = collect($data['properties']);

                            foreach ($datum['properties'] as $item) {
                                $property = $properties->firstWhere('external_id', $item['id']);
                                $variant = collect($property['variants'])->firstWhere('id', $item['variant']);

                                if ($variant) {
                                    $attribute = $property['attribute'];
                                    $attributes[$attribute->getUuid()->toString()] = $variant['value'];
                                }
                            }

                            $catalogProductAttributeService->proccess($datum['product'], $attributes);
                        }

                        // если есть файлы - удаляем, пишем новые
                        // скорей всего новые файлы получатся только в случае изменения - xz
                        if ($datum['files']) {
                            foreach ($datum['product']->getFiles() as $file) {
                                $fileRelationService->delete($file);
                            }

                            foreach ($datum['files'] as $index => $file) {
                                if (($file = $fileService->read(['name' => $file])->first()) !== null) {
                                    $fileRelationService->create([
                                        'entity' => $datum['product'],
                                        'file' => $file,
                                        'order' => $index + 1,
                                    ]);
                                }
                            }
                        }

                    }
                }

                $this->setProgress($index, count($files));
            }
        }

        return $this->setStatusDone();
    }

    protected function parseCategories(array $data = [], $parent = \Ramsey\Uuid\Uuid::NIL): array
    {
        $output = [];

        foreach ($data['Группы']['Группа'] as $group) {
            $output[] = $item = [
                'title' => $group['Наименование'][0],
                'external_id' => $group['Ид'][0],
                'parent' => $parent,
            ];

            if (!empty($group['Группы'])) {
                $output = array_merge($output, $this->parseCategories($group, $item['external_id']));
            }
        }

        return $output;
    }

    protected function parseProperties(array $data): array
    {
        $output = [];

        foreach ($data['Свойства'] as $property) {
            $item = [
                'title' => $property['Наименование'][0],
                'external_id' => $property['Ид'][0],
                'variants' => [],
            ];

            if (!empty($property['ВариантыЗначений']['Справочник'])) {
                foreach ($property['ВариантыЗначений']['Справочник'] as $value) {
                    $item['variants'][] = [
                        'id' => $value['ИдЗначения'][0],
                        'value' => $value['Значение'][0],
                    ];
                }
            }

            $output[] = $item;
        }

        return $output;
    }

    protected function parseProducts(array $data): array
    {
        $output = [];

        foreach ($data['Товары']['Товар'] as $product) {
            $item = [
                'title' => $product['Наименование'][0],
                'external_id' => $product['Ид'][0],
                'category' => $product['Группы']['Ид'][0] ?? \Ramsey\Uuid\Uuid::NIL,
                'barcode' => $product['Штрихкод'][0] ?? '',
                'vendorcode' => $product['Артикул'][0] ?? '',
                'description' => $product['Описание'][0] ?? '',
                'unit' => 'шт',
                'volume' => $product['Вес'][0] ?? 0,
                'field3' => $product['Ширина'][0] ?? '',
                'field4' => $product['Длина'][0] ?? '',
                'field5' => $product['Высота'][0] ?? '',
                'properties' => [],
                'files' => array_map(fn($path) => explode('.', str_replace('/', '', $path))[0] ?? '', $product['Картинка'] ?? []),
            ];

            if (!empty($product['ЗначенияСвойств'])) {
                foreach ($product['ЗначенияСвойств'] as $property) {
                    $item['properties'][] = [
                        'id' => $property['Ид'][0] ?? '',
                        'variant' => $property['Значение'][0] ?? '',
                    ];
                }
            }

            $output[] = $item;
        }

        return $output;
    }

    private bool $bOptimize = false;

    private function parseXml(XMLReader $reader): array
    {
        $output = null;
        $iDc = -1;

        while ($reader->read()) {
            switch ($reader->nodeType) {

                case XMLReader::END_ELEMENT:
                    if ($this->bOptimize) {
                        $this->optXml($output);
                    }

                    return $output;

                case XMLReader::ELEMENT:
                    if (!isset($output[$reader->name])) {
                        if ($reader->hasAttributes) {
                            $output[$reader->name][] = $reader->isEmptyElement ? '' : $this->parseXML($reader);
                        } else {
                            if ($reader->isEmptyElement) {
                                $output[$reader->name] = '';
                            } else {
                                $output[$reader->name] = $this->parseXML($reader);
                            }
                        }
                    } elseif (is_array($output[$reader->name])) {
                        if (!isset($output[$reader->name][0])) {
                            $temp = $output[$reader->name];
                            foreach ($temp as $sKey => $sValue) {
                                unset($output[$reader->name][$sKey]);
                            }
                            $output[$reader->name][] = $temp;
                        }

                        if ($reader->hasAttributes) {
                            $output[$reader->name][] = $reader->isEmptyElement ? '' : $this->parseXML($reader);
                        } else {
                            if ($reader->isEmptyElement) {
                                $output[$reader->name][] = '';
                            } else {
                                $output[$reader->name][] = $this->parseXML($reader);
                            }
                        }
                    } else {
                        $mOldVar = $output[$reader->name];
                        $output[$reader->name] = [$mOldVar];

                        if ($reader->hasAttributes) {
                            $output[$reader->name][] = $reader->isEmptyElement ? '' : $this->parseXML($reader);
                        } else {
                            if ($reader->isEmptyElement) {
                                $output[$reader->name][] = '';
                            } else {
                                $output[$reader->name][] = $this->parseXML($reader);
                            }
                        }
                    }

                    if ($reader->hasAttributes) {
                        $mElement =& $output[$reader->name][count($output[$reader->name]) - 1];
                        while ($reader->moveToNextAttribute()) {
                            $mElement[$reader->name] = $reader->value;
                        }
                    }

                    break;

                case XMLReader::TEXT:
                case XMLReader::CDATA:
                    $output[++$iDc] = $reader->value;

            }
        }

        return $output;
    }

    private function optXml(&$mData): void
    {
        if (is_array($mData)) {
            if (isset($mData[0]) && count($mData) === 1) {
                $mData = $mData[0];
                if (is_array($mData)) {
                    foreach ($mData as &$aSub) {
                        $this->optXml($aSub);
                    }
                }
            } else {
                foreach ($mData as &$aSub) {
                    $this->optXml($aSub);
                }
            }
        }
    }
}
