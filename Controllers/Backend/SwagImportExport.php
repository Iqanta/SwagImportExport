<?php

/**
 * Shopware 4
 * Copyright © shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */
use Shopware\Components\SwagImportExport\DataWorkflow;

/**
 * Shopware ImportExport Plugin
 *
 * @category Shopware
 * @package Shopware\Plugins\SwagImageEditor
 * @copyright Copyright (c) shopware AG (http://www.shopware.de)
 */
class Shopware_Controllers_Backend_SwagImportExport extends Shopware_Controllers_Backend_ExtJs
{

    /**
     * Contains the shopware model manager
     *
     * @var Shopware\Components\Model\ModelManager
     */
    protected $manager;

    /**
     * @var Shopware\CustomModels\ImportExport\Profile
     */
    protected $profileRepository;

    /**
     * @var Shopware\CustomModels\ImportExport\Session
     */
    protected $sessionRepository;

    /**
     * @var Shopware\CustomModels\ImportExport\Expression
     */
    protected $expressionRepository;

    /**
     * Converts the JSON tree to ExtJS tree
     *  
     * @TODO: move code to component
     */
    protected function convertToExtJSTree($node, $isInIteration = false, $adapter = '')
    {
        $isLeaf = true;
        $parentKey = '';
        $children = array();
        
        if ($node['type'] == 'iteration') {
            $isInIteration = true;
            $adapter = $node['adapter'];
            $parentKey = $node['parentKey'];
            
            $icon = 'sprite-blue-folders-stack';
        } else if ($node['type'] == 'leaf') {
            $icon = 'sprite-icon_taskbar_top_inhalte_active';
        } else {
            $icon = '';
            $isLeaf = false;
        }

        // Get the attributes
        if (isset($node['attributes'])) {
            foreach ($node['attributes'] as $attribute) {
                $children[] = array(
                    'id' => $attribute['id'],
                    'text' => $attribute['name'],
                    'type' => $attribute['type'],
                    'index' => $attribute['index'],
                    'adapter' => $adapter,
                    'leaf' => true,
                    'iconCls' => 'sprite-sticky-notes-pin',
                    'type' => 'attribute',
                    'swColumn' => $attribute['shopwareField'],
                    'inIteration' => $isInIteration
                );
            }
            
            $isLeaf = false;
        }

        // Get the child nodes
        if (isset($node['children']) && count($node['children']) > 0) {
            foreach ($node['children'] as $child) {
                $children[] = $this->convertToExtJSTree($child, $isInIteration, $adapter);
            }
            
            $isLeaf = false;
        }

        return array(
            'id' => $node['id'],
            'type' => $node['type'],
            'index' => $node['index'],
            'text' => $node['name'],
            'adapter' => $adapter,
            'parentKey' => $parentKey,
            'leaf' => $isLeaf,
            'expanded' => !$isLeaf,
            'iconCls' => $icon,
            'swColumn' => $node['shopwareField'],
            'inIteration' => $isInIteration,
            'children' => $children
        );
    }

    /**
     * Helper function which appends child node to the tree
     */
    protected function appendNode($child, &$node)
    {
        if ($node['id'] == $child['parentId']) {
            if ($child['type'] == 'attribute') {
                $node['attributes'][] = array(
                    'id' => $child['id'],
                    'name' => $child['text'],
                    'shopwareField' => $child['swColumn'],
                );
            } else if ($child['type'] == 'node') {
                $node['children'][] = array(
                    'id' => $child['id'],
                    'name' => $child['text'],
                    'shopwareField' => $child['swColumn'],
                );
            } else if ($child['type'] == 'iteration') {
                $node['children'][] = array(
                    'id' => $child['id'],
                    'name' => $child['text'],
                    'adapter' => $child['adapter'],
                    'parentKey' => $child['parentKey'],
                );
            } else {
                $node['children'][] = array(
                    'id' => $child['id'],
                    'name' => $child['text'],
                );
            }

            return true;
        } else {
            if (isset($node['children'])) {
                foreach ($node['children'] as &$childNode) {
                    if ($this->appendNode($child, $childNode)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Helper function which finds node from the tree
     */
    protected function getNodeById($id, $node, $parentId = 'root')
    {
        if ($node['id'] == $id) {
            $node['parentId'] = $parentId;
            return $node;
        } else {
            if (isset($node['attributes'])) {
                foreach ($node['attributes'] as $attribute) {
                    $result = $this->getNodeById($id, $attribute, $node['id']);
                    if ($result !== false) {
                        return $result;
                    }
                }
            }
            if (isset($node['children'])) {
                foreach ($node['children'] as $childNode) {
                    $result = $this->getNodeById($id, $childNode, $node['id']);
                    if ($result !== false) {
                        return $result;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Helper function which appends child node to the tree
     */
    protected function moveNode($child, &$node)
    {
        if ($node['id'] == $child['parentId']) {
            if ($child['type'] == 'attribute') {
                unset($child['parentId']);
                unset($child['type']);
                $node['attributes'][] = $child;
            } else if ($child['type'] == 'node') {
                unset($child['parentId']);
                unset($child['type']);
                $node['children'][] = $child;
            } else {
                unset($child['parentId']);
                unset($child['type']);
                $node['children'][] = $child;
            }
            
            return true;
        } else {
            if (isset($node['children'])) {
                foreach ($node['children'] as &$childNode) {
                    if ($this->moveNode($child, $childNode)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Helper function which finds and changes node from the tree
     */
    protected function changeNode($child, &$node)
    {
        if ($node['id'] == $child['id']) {
            $node['name'] = $child['text'];
            $node['index'] = $child['index'];
            if (isset($child['swColumn'])) {
                $node['shopwareField'] = $child['swColumn'];
            } else {
                unset($node['shopwareField']);
            }

            if ($child['type'] == 'iteration') {
                if (isset($child['adapter'])) {
                    $node['adapter'] = $child['adapter'];
                } else {
                    unset($node['adapter']);
                }
                if (isset($child['parentKey'])) {
                    $node['parentKey'] = $child['parentKey'];
                } else {
                    unset($node['parentKey']);
                }
            }

            return true;
        } else {
            if (isset($node['children'])) {
                foreach ($node['children'] as &$childNode) {
                    if ($this->changeNode($child, $childNode)) {
                        return true;
                    }
                }
            }
            if (isset($node['attributes'])) {
                foreach ($node['attributes'] as &$childNode) {
                    if ($this->changeNode($child, $childNode)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Helper function which finds and deletes node from the tree
     */
    protected function deleteNode($child, &$node)
    {
        if (isset($node['children'])) {
            foreach ($node['children'] as $key => &$childNode) {
                if ($childNode['id'] == $child['id']) {
                    unset($node['children'][$key]);
                    return true;
                } else if ($this->deleteNode($child, $childNode)) {
                    return true;
                }
            }
        }
        if (isset($node['attributes'])) {
            foreach ($node['attributes'] as $key => &$childNode) {
                if ($childNode['id'] == $child['id']) {
                    unset($node['attributes'][$key]);
                    return true;
                }
            }
        }

        return false;
    }

    public function getProfileAction()
    {
        $profileId = $this->Request()->getParam('profileId', 1);
        $profileRepository = $this->getProfileRepository();
        $profileEntity = $profileRepository->findOneBy(array('id' => $profileId));

        $tree = $profileEntity->getTree();
        $root = $this->convertToExtJSTree(json_decode($tree, 1));

        $this->View()->assign(array('success' => true, 'children' => $root['children']));
    }

    public function createNodeAction()
    {
        $profileId = $this->Request()->getParam('profileId', 1);
        $data = $this->Request()->getParam('data', 1);
        $profileRepository = $this->getProfileRepository();
        $profileEntity = $profileRepository->findOneBy(array('id' => $profileId));

        $tree = json_decode($profileEntity->getTree(), 1);

        if (isset($data['parentId'])) {
            $data = array($data);
        }

        $errors = false;
        
        foreach ($data as &$node) {
            $node['id'] = uniqid();
            if (!$this->appendNode($node, $tree)) {
                $errors = true;
            }
        }

        $profileEntity->setTree(json_encode($tree));

        $this->getManager()->persist($profileEntity);
        $this->getManager()->flush();

        if ($errors) {
            $this->View()->assign(array('success' => false, 'message' => 'Some of the nodes could not be saved', 'children' => $data));
        } else {
            $this->View()->assign(array('success' => true, 'children' => $data));
        }
    }

    public function updateNodeAction()
    {
        $profileId = $this->Request()->getParam('profileId', 1);
        $data = $this->Request()->getParam('data', 1);
        $profileRepository = $this->getProfileRepository();
        $profileEntity = $profileRepository->findOneBy(array('id' => $profileId));

        $tree = json_decode($profileEntity->getTree(), 1);

        if (isset($data['parentId'])) {
            $data = array($data);
        }

        $errors = false;
        
        foreach ($data as &$node) {
            if (!$this->changeNode($node, $tree)) {
                $errors = true;
                break;
            }
            
            $changedNode = $this->getNodeById($node['id'], $tree);
            
            if ($node['parentId'] != $changedNode['parentId']) {
                $changedNode['parentId'] = $node['parentId'];
                $changedNode['type'] = $node['type'];
                if (!$this->deleteNode($node, $tree)) {
                    $errors = true;
                    break;
                } else if (!$this->moveNode($changedNode, $tree)) {
                    $errors = true;
                    break;
                }
            }
        }
        $profileEntity->setTree(json_encode($tree));

        $this->getManager()->persist($profileEntity);
        $this->getManager()->flush();

        if ($errors) {
            $this->View()->assign(array('success' => false, 'message' => 'Some of the nodes could not be saved', 'children' => $data));
        } else {
            $this->View()->assign(array('success' => true, 'children' => $data));
        }
    }

    public function deleteNodeAction()
    {
        $profileId = $this->Request()->getParam('profileId', 1);
        $data = $this->Request()->getParam('data', 1);
        $profileRepository = $this->getProfileRepository();
        $profileEntity = $profileRepository->findOneBy(array('id' => $profileId));

        $tree = json_decode($profileEntity->getTree(), 1);

        if (isset($data['parentId'])) {
            $data = array($data);
        }

        $errors = false;

        foreach ($data as &$node) {
            if (!$this->deleteNode($node, $tree)) {
                $errors = true;
            }
        }

        $profileEntity->setTree(json_encode($tree));

        $this->getManager()->persist($profileEntity);
        $this->getManager()->flush();

        if ($errors) {
            $this->View()->assign(array('success' => false, 'message' => 'Some of the nodes could not be saved', 'children' => $data));
        } else {
            $this->View()->assign(array('success' => true, 'children' => $data));
        }
    }

    /**
     * Returns the new profile
     */
    public function createProfilesAction()
    {
        $data = $this->Request()->getParam('data', 1);

        try {
            switch ($data['type']) {
                case 'categories':
                    $newTree = '{"id":"root","name":"Root","type":"node","children":[{"id":"537359399c80a","name":"Header","index":0,"type":"node","children":[{"id":"537385ed7c799","name":"HeaderChild","index":0,"type":"node","shopwareField":""}]},{"id":"537359399c8b7","name":"Categories","index":1,"type":"node","children":[{"id":"537359399c90d","name":"Category","index":0,"type":"iteration","adapter":"default","attributes":[{"id":"53738653da10f","name":"show_filter_groups","index":0,"shopwareField":"showFilterGroups"}],"children":[{"id":"5373865547d06","name":"Id","index":1,"type":"leaf","shopwareField":"id"},{"id":"537386ac3302b","name":"Description","index":2,"type":"node","shopwareField":"description","attributes":[{"id":"53738718f26db","name":"Active","index":0,"shopwareField":"active"}],"children":[{"id":"5373870d38c80","name":"Value","index":1,"type":"leaf","shopwareField":"name"}]},{"id":"537388742e20e","name":"Title","index":3,"type":"leaf","shopwareField":"name"}]}]}]}';
                    break;
                case 'articles':
                    $newTree = '{"id":"root","name":"Root","type":"node","children":[{"id":"2","name":"Header","index":0,"type":"node","children":[{"id":"3","name":"HeaderChild","index":0,"type":"node"}]},{"id":"4","name":"Articles","index":1,"type":"node","children":[{"id":"5","name":"Article","index":0,"type":"iteration","adapter":"article","attributes":[{"id":"6","name":"variantId","index":0,"shopwareField":"variantId"},{"id":"7","name":"orderNumber","index":1,"shopwareField":"orderNumber"}],"children":[{"id":"8","name":"mainNumber","index":2,"type":"leaf","shopwareField":"mainNumber"},{"id":"9","name":"name","index":3,"type":"leaf","shopwareField":"name"},{"id":"10","name":"tax","index":4,"type":"leaf","shopwareField":"tax"},{"id":"11","name":"supplierName","index":5,"type":"leaf","shopwareField":"supplierName"},{"id":"12","name":"additionalText","index":6,"type":"leaf","shopwareField":"additionalText","attributes":[{"id":"13a","name":"inStock","index":0,"shopwareField":"inStock"}]},{"id":"13","name":"Prices","index":7,"type":"node","children":[{"id":"14","name":"Price","index":0,"type":"iteration","adapter":"price","parentKey":"variantId","attributes":[{"id":"15","name":"group","index":0,"shopwareField":"priceGroup"}],"children":[{"id":"16","name":"pricegroup","index":1,"type":"leaf","shopwareField":"priceGroup"},{"id":"17","name":"price","index":2,"type":"leaf","shopwareField":"price"}]}]},{"id":"53ccd2dbcd345","name":"Similars","index":8,"type":"node","shopwareField":"","children":[{"id":"53ccd3b232713","name":"similar","index":0,"type":"iteration","adapter":"similar","parentKey":"articleId","shopwareField":"","children":[{"id":"53ccd4586f580","name":"similarId","index":0,"type":"leaf","shopwareField":"similarId"}]}]},{"id":"53ccd51b807bc","name":"Images","index":9,"type":"node","shopwareField":"","children":[{"id":"53ccd529c4019","name":"image","index":0,"type":"iteration","adapter":"image","parentKey":"articleId","shopwareField":"","children":[{"id":"53ccd58e8bb25","name":"image_name","index":0,"type":"leaf","shopwareField":"path"}]}]}]}]}]}';
                    break;
                case 'articlesInStock':
                    $newTree = '{"id":"root","name":"Root","type":"node","children":[{"id":"537359399c80a","name":"Header","index":0,"type":"node","children":[{"id":"537385ed7c799","name":"HeaderChild","index":0,"type":"node","shopwareField":""}]},{"id":"537359399c8b7","name":"articlesInStock","index":1,"type":"node","shopwareField":"","children":[{"id":"537359399c90d","name":"article","index":0,"type":"iteration","adapter":"default","parentKey":"","shopwareField":"","attributes":[],"children":[{"id":"5373865547d06","name":"article_number","index":0,"type":"leaf","shopwareField":"orderNumber"},{"id":"537386ac3302b","name":"Description","index":1,"type":"node","shopwareField":"description","attributes":[{"id":"53738718f26db","name":"supplier","index":0,"shopwareField":"supplier"}],"children":[{"id":"5373870d38c80","name":"Value","index":1,"type":"leaf","shopwareField":"additionalText"}]},{"id":"537388742e20e","name":"inStock","index":2,"type":"leaf","shopwareField":"inStock"}]}]}]}';
                    break;
                case 'articlesPrices':
                    $newTree = '{"id":"root","name":"Root","type":"node","children":[{"id":"537359399c80a","name":"Header","index":0,"type":"node","children":[{"id":"537385ed7c799","name":"HeaderChild","index":0,"type":"node","shopwareField":""}]},{"id":"537359399c8b7","name":"Prices","index":1,"type":"node","shopwareField":"","children":[{"id":"537359399c90d","name":"Price","index":0,"type":"iteration","adapter":"default","parentKey":"","shopwareField":"","attributes":[{"id":"53738653da10f","name":"articleDetailsId","index":0,"shopwareField":"articleDetailsId"}],"children":[{"id":"5373865547d06","name":"articleId","index":1,"type":"leaf","shopwareField":"articleId"},{"id":"537386ac3302b","name":"Description","index":2,"type":"node","shopwareField":"description","attributes":[{"id":"53738718f26db","name":"from","index":0,"shopwareField":"from"}],"children":[{"id":"5373870d38c80","name":"to","index":1,"type":"leaf","shopwareField":"to"}]},{"id":"537388742e20e","name":"price","index":3,"type":"leaf","shopwareField":"price"}]}]}]}';
                    break;
                case 'articlesTranslations':
                    $newTree = '{"id":"root","name":"Root","type":"node","children":[{"id":"537359399c80a","name":"Header","index":0,"type":"node","children":[{"id":"537385ed7c799","name":"HeaderChild","index":0,"type":"node","shopwareField":""}]},{"id":"537359399c8b7","name":"Translations","index":1,"type":"node","shopwareField":"","children":[{"id":"537359399c90d","name":"Translation","index":0,"type":"iteration","adapter":"default","parentKey":"","shopwareField":"","attributes":[{"id":"53738653da10f","name":"article_number","index":0,"shopwareField":"articleNumber"}],"children":{"2":{"id":"53ce5e8f25a24","name":"title","index":1,"type":"leaf","shopwareField":"title"},"3":{"id":"53ce5f9501db7","name":"description","index":2,"type":"leaf","shopwareField":"description"},"4":{"id":"53ce5fa3bd231","name":"long_description","index":3,"type":"leaf","shopwareField":"descriptionLong"},"5":{"id":"53ce5fb6d95d8","name":"keywords","index":4,"type":"leaf","shopwareField":"keywords"}}}]}]}';
                    break;
                case 'orders':
                    $newTree = '{"id":"root","name":"Root","type":"node","children":[{"id":"537359399c80a","name":"Header","index":0,"type":"node","children":[{"id":"537385ed7c799","name":"HeaderChild","index":0,"type":"node","shopwareField":""}]},{"id":"537359399c8b7","name":"Orders","index":1,"type":"node","children":[{"id":"537359399c90d","name":"Order","index":0,"type":"iteration","adapter":"order","attributes":[{"id":"53738653da10f","name":"Attribute1","index":0,"shopwareField":"parent"}],"children":[{"id":"5373865547d06","name":"Id","index":1,"type":"leaf","shopwareField":"id"},{"id":"537386ac3302b","name":"Description","index":2,"type":"node","shopwareField":"description","attributes":[{"id":"53738718f26db","name":"Attribute2","index":0,"shopwareField":"active"}],"children":[{"id":"5373870d38c80","name":"Value","index":1,"type":"leaf","shopwareField":"description"}]},{"id":"537388742e20e","name":"Title","index":3,"type":"leaf","shopwareField":"description"}]}]}]}';
                    break;
                case 'customers':
                    $newTree = '{"id":"root","name":"Root","type":"node","children":[{"id":"537359399c80a","name":"Header","index":0,"type":"node","children":[{"id":"537385ed7c799","name":"HeaderChild","index":0,"type":"node","shopwareField":""}]},{"id":"537359399c8b7","name":"customers","index":1,"type":"node","shopwareField":"","children":[{"id":"537359399c90d","name":"customer","index":0,"type":"iteration","adapter":"default","parentKey":"","shopwareField":"","attributes":[{"id":"53738653da10f","name":"password","index":0,"shopwareField":"password"}],"children":[{"id":"5373865547d06","name":"Id","index":1,"type":"leaf","shopwareField":"id"},{"id":"537386ac3302b","name":"billing_info","index":2,"type":"node","shopwareField":"description","attributes":[],"children":{"1":{"id":"53cd02b45a066","name":"first_name","index":0,"type":"leaf","shopwareField":"billingFirstname"},"2":{"id":"53cd0343c19c2","name":"last_name","index":1,"type":"leaf","shopwareField":"shippingLastname"}}},{"id":"537388742e20e","name":"shipping_info","index":3,"type":"node","shopwareField":"encoder","children":[{"id":"53cd02fa7025a","name":"first_name","index":0,"type":"leaf","shopwareField":"shippingFirstname"},{"id":"53cd031bb402c","name":"last_name","index":1,"type":"leaf","shopwareField":"shippingLastname"}]},{"id":"53cd036e8d9f3","name":"encoder","index":4,"type":"leaf","shopwareField":"encoder"}]}]}]}';
                    break;
                case 'newsletter':
                    $newTree = '{"id":"root","name":"Root","type":"node","children":[{"id":"537359399c80a","name":"Header","index":0,"type":"node","children":[{"id":"537385ed7c799","name":"HeaderChild","index":0,"type":"node","shopwareField":""}]},{"id":"537359399c8b7","name":"Users","index":1,"type":"node","shopwareField":"","children":[{"id":"537359399c90d","name":"user","index":0,"type":"iteration","adapter":"default","parentKey":"","shopwareField":"","attributes":[{"id":"53738653da10f","name":"userID","index":0,"shopwareField":"userID"}],"children":[{"id":"5373865547d06","name":"email","index":1,"type":"leaf","shopwareField":"email"},{"id":"537386ac3302b","name":"Information","index":2,"type":"node","shopwareField":"description","attributes":[],"children":[{"id":"5373870d38c80","name":"salutation","index":0,"type":"leaf","shopwareField":"salutation"},{"id":"53cd096c0e116","name":"first_name","index":1,"type":"leaf","shopwareField":"firstName"},{"id":"53cd098005374","name":"last_name","index":2,"type":"leaf","shopwareField":"lastName"},{"id":"53cd09a440859","name":"street","index":3,"type":"leaf","shopwareField":"street"},{"id":"53cd09b26e7dc","name":"street_number","index":4,"type":"leaf","shopwareField":"streetNumber"},{"id":"53cd09c6c183e","name":"city","index":5,"type":"leaf","shopwareField":"city"},{"id":"53cd09d35b7c5","name":"zip_code","index":6,"type":"leaf","shopwareField":"zipCode"}]},{"id":"537388742e20e","name":"group","index":3,"type":"leaf","shopwareField":"groupName"},{"id":"53cd09ff37910","name":"last_read","index":4,"type":"leaf","shopwareField":"lastRead"}]}]}]}';
                    break;
                default :
                    throw new \Exception('The profile could not be created.');
            }

            $profile = new \Shopware\CustomModels\ImportExport\Profile();

            $profile->setName($data['name']);
            $profile->setType($data['type']);
            $profile->setTree($newTree);

            $this->getManager()->persist($profile);
            $this->getManager()->flush();

            $this->View()->assign(array(
                'success' => true,
                'data' => array(
                    "id" => $profile->getId(),
                    'name' => $profile->getName(),
                    'type' => $profile->getType(),
                    'tree' => $profile->getTree(),
                )
            ));
        } catch (\Exception $e) {
            $this->View()->assign(array('success' => false, 'msg' => $e->getMessage()));
        }
    }

    /**
     * Returns all profiles into an array
     */
    public function getProfilesAction()
    {
        $profileRepository = $this->getProfileRepository();

        $query = $profileRepository->getProfilesListQuery(
                        $this->Request()->getParam('filter', array()), $this->Request()->getParam('sort', array()), $this->Request()->getParam('limit', null), $this->Request()->getParam('start')
                )->getQuery();

        $count = $this->getManager()->getQueryCount($query);

        $data = $query->getArrayResult();

        $this->View()->assign(array(
            'success' => true, 'data' => $data, 'total' => $count
        ));
    }

    public function deleteProfilesAction()
    {
        $data = $this->Request()->getParam('data', 1);

        if (isset($data['id'])) {
            $data = array($data);
        }

        try {
            $profileRepository = $this->getProfileRepository();
            foreach ($data as $profile) {
                $profileEntity = $profileRepository->findOneBy(array('id' => $profile['id']));
                $this->getManager()->remove($profileEntity);
            }
            $this->getManager()->flush();
        } catch (\Exception $e) {
            $this->View()->assign(array('success' => false, 'msg' => 'Unexpected error. The profile could not be deleted.', 'children' => $data));
        }
        $this->View()->assign(array('success' => true));
    }

    public function getConversionsAction()
    {
        $profileId = $this->Request()->getParam('profileId');
        $filter = $this->Request()->getParam('filter', array());

        $expressionRepository = $this->getExpressionRepository();

        $filter = array_merge(array('p.id' => $profileId), $filter);

        $query = $expressionRepository->getExpressionsListQuery(
                        $filter, $this->Request()->getParam('sort', array()), $this->Request()->getParam('limit', null), $this->Request()->getParam('start')
                )->getQuery();

        $count = Shopware()->Models()->getQueryCount($query);

        $data = $query->getArrayResult();

        $this->View()->assign(array(
            'success' => true, 'data' => $data, 'total' => $count
        ));
    }

    public function createConversionAction()
    {
        $profileId = $this->Request()->getParam('profileId');
        $data = $this->Request()->getParam('data', 1);

        $profileRepository = $this->getProfileRepository();
        $profileEntity = $profileRepository->findOneBy(array('id' => $profileId));

        $expressionEntity = new \Shopware\CustomModels\ImportExport\Expression();

        $expressionEntity->setProfile($profileEntity);
        $expressionEntity->setVariable($data['variable']);
        $expressionEntity->setExportConversion($data['exportConversion']);
        $expressionEntity->setImportConversion($data['importConversion']);

        Shopware()->Models()->persist($expressionEntity);
        Shopware()->Models()->flush();

        $this->View()->assign(array(
            'success' => true,
            'data' => array(
                "id" => $expressionEntity->getId(),
                'profileId' => $expressionEntity->getProfile()->getId(),
                'exportConversion' => $expressionEntity->getExportConversion(),
                'importConversion' => $expressionEntity->getImportConversion(),
            )
        ));
    }

    public function updateConversionAction()
    {
        $profileId = $this->Request()->getParam('profileId', 1);
        $data = $this->Request()->getParam('data', 1);

        if (isset($data['id'])) {
            $data = array($data);
        }

        $expressionRepository = $this->getExpressionRepository();

        try {
            foreach ($data as $expression) {
                $expressionEntity = $expressionRepository->findOneBy(array('id' => $expression['id']));
                $expressionEntity->setVariable($expression['variable']);
                $expressionEntity->setExportConversion($expression['exportConversion']);
                $expressionEntity->setImportConversion($expression['importConversion']);
                Shopware()->Models()->persist($expressionEntity);
            }

            Shopware()->Models()->flush();

            $this->View()->assign(array('success' => true, 'data' => $data));
        } catch (\Exception $e) {
            $this->View()->assign(array('success' => false, 'message' => $e->getMessage(), 'data' => $data));
        }
    }

    public function deleteConversionAction()
    {
        $profileId = $this->Request()->getParam('profileId', 1);
        $data = $this->Request()->getParam('data', 1);

        if (isset($data['id'])) {
            $data = array($data);
        }

        $expressionRepository = $this->getExpressionRepository();

        try {
            foreach ($data as $expression) {
                $expressionEntity = $expressionRepository->findOneBy(array('id' => $expression['id']));
                Shopware()->Models()->remove($expressionEntity);
            }

            Shopware()->Models()->flush();

            $this->View()->assign(array('success' => true, 'data' => $data));
        } catch (\Exception $e) {
            $this->View()->assign(array('success' => false, 'message' => $e->getMessage(), 'data' => $data));
        }
    }

    public function prepareExportAction()
    {
        $variants = $this->Request()->getParam('variants') ? true : false;
        
        if ($this->Request()->getParam('limit')) {
            $limit = $this->Request()->getParam('limit');
        }
        
        if ($this->Request()->getParam('offset')) {
            $offset = $this->Request()->getParam('offset');
        }
        
        $postData = array(
            'sessionId' => $this->Request()->getParam('sessionId'),
            'profileId' => (int) $this->Request()->getParam('profileId'),
            'type' => 'export',
            'format' => $this->Request()->getParam('format'),
            'filter' =>  array(),
            'limit' =>  array(
                'limit' => $limit,
                'offset' => $offset,
            ),
        );
        
        if ($variants) {
            $postData['filter']['variants'] = $variants;
        }
        
        try {
            $profile = $this->Plugin()->getProfileFactory()->loadProfile($postData);

            $dataFactory = $this->Plugin()->getDataFactory();

            $dbAdapter = $dataFactory->createDbAdapter($profile->getType());
            $dataSession = $dataFactory->loadSession($postData);

            $dataIO = $dataFactory->createDataIO($dbAdapter, $dataSession);

            $colOpts = $dataFactory->createColOpts($postData['columnOptions']);
            $limit = $dataFactory->createLimit($postData['limit']);            
            $filter = $dataFactory->createFilter($postData['filter']);
            $maxRecordCount = $postData['max_record_count'];
            $type = $postData['type'];
            $format = $postData['format'];

            $dataIO->initialize($colOpts, $limit, $filter, $maxRecordCount, $type, $format);

            $ids = $dataIO->preloadRecordIds()->getRecordIds();

            $position = $dataIO->getSessionPosition();
            $position = $position == null ? 0 : $position;

            $this->View()->assign(array('success' => true, 'position' => $position, 'count' => count($ids)));
        } catch (Exception $e) {
            $this->View()->assign(array('success' => false, 'msg' => $e->getMessage()));
        }
    }

    public function exportAction()
    {
        $variants = $this->Request()->getParam('variants') ? true : false;

        if ($this->Request()->getParam('limit')) {
            $limit = $this->Request()->getParam('limit');
        }
        
        if ($this->Request()->getParam('offset')) {
            $offset = $this->Request()->getParam('offset');
        }
        
        $postData = array(
            'profileId' => (int) $this->Request()->getParam('profileId'),
            'type' => 'export',
            'format' => $this->Request()->getParam('format'),
            'sessionId' => $this->Request()->getParam('sessionId'),
            'fileName' => $this->Request()->getParam('fileName'),
            'filter' =>  array(),
            'limit' =>  array(
                'limit' => $limit,
                'offset' => $offset,
            ),
        );
        
        if ($variants) {
            $postData['filter']['variants'] = $variants;
        }

        $profile = $this->Plugin()->getProfileFactory()->loadProfile($postData);

        $dataFactory = $this->Plugin()->getDataFactory();

        $dbAdapter = $dataFactory->createDbAdapter($profile->getType());
        $dataSession = $dataFactory->loadSession($postData);

        //create dataIO
        $dataIO = $dataFactory->createDataIO($dbAdapter, $dataSession);

        $colOpts = $dataFactory->createColOpts($postData['columnOptions']);
        $limit = $dataFactory->createLimit($postData['limit']);
        $filter = $dataFactory->createFilter($postData['filter']);
        $maxRecordCount = $postData['max_record_count'];
        $type = $postData['type'];
        $format = $postData['format'];

        $dataIO->initialize($colOpts, $limit, $filter, $type, $format, $maxRecordCount);

        // we create the file writer that will write (partially) the result file
        $fileFactory = $this->Plugin()->getFileIOFactory();
        $fileHelper = $fileFactory->createFileHelper();
        $fileWriter = $fileFactory->createFileWriter($postData, $fileHelper);

        $dataTransformerChain = $this->Plugin()->getDataTransformerFactory()->createDataTransformerChain(
                $profile, array('isTree' => $fileWriter->hasTreeStructure())
        );

        $dataWorkflow = new DataWorkflow($dataIO, $profile, $dataTransformerChain, $fileWriter);

        try {
            $post = $dataWorkflow->export($postData);

            return $this->View()->assign(array('success' => true, 'data' => $post));
        } catch (Exception $e) {
            return $this->View()->assign(array('success' => false, 'msg' => $e->getMessage()));
        }
    }

    public function prepareImportAction()
    {
        $postData = array(
            'sessionId' => $this->Request()->getParam('sessionId'),
            'profileId' => (int) $this->Request()->getParam('profileId'),
            'type' => 'import',
            'file' => $this->Request()->getParam('importFile')
        );

        if (empty($postData['file'])) {
            return $this->View()->assign(array('success' => false, 'msg' => 'Not valid file'));
        }

        //get file format
        $inputFileName = Shopware()->DocPath() . $postData['file'];
        $extension = pathinfo($inputFileName, PATHINFO_EXTENSION);

        if (!$this->isFormatValid($extension)) {
            return $this->View()->assign(array('success' => false, 'msg' => 'Not valid file format'));
        }

        $postData['format'] = $extension;

        $profile = $this->Plugin()->getProfileFactory()->loadProfile($postData);

        //get profile type
        $postData['adapter'] = $profile->getType();

        // we create the file reader that will read the result file
        $fileReader = $this->Plugin()->getFileIOFactory()->createFileReader($postData);

        if ($extension === 'xml') {
            $tree = json_decode($profile->getConfig("tree"), true);
            $fileReader->setTree($tree);
        }

        $dataFactory = $this->Plugin()->getDataFactory();

        $dbAdapter = $dataFactory->createDbAdapter($profile->getType());
        $dataSession = $dataFactory->loadSession($postData);

        //create dataIO
        $dataIO = $dataFactory->createDataIO($dbAdapter, $dataSession);

        $position = $dataIO->getSessionPosition();
        $position = $position == null ? 0 : $position;

        $totalCount = $fileReader->getTotalCount($inputFileName);

        return $this->View()->assign(array('success' => true, 'position' => $position, 'count' => $totalCount));
    }

    public function importAction()
    {
        $postData = array(
            'type' => 'import',
            'profileId' => (int) $this->Request()->getParam('profileId'),
            'importFile' => $this->Request()->getParam('importFile'),
            'sessionId' => $this->Request()->getParam('sessionId')
        );

        $inputFile = Shopware()->DocPath() . $postData['importFile'];
        if (!isset($postData['format'])) {
            //get file format
            $postData['format'] = pathinfo($inputFile, PATHINFO_EXTENSION);
        }

        // we create the file reader that will read the result file
        $fileReader = $this->Plugin()->getFileIOFactory()->createFileReader($postData);

        //load profile
        $profile = $this->Plugin()->getProfileFactory()->loadProfile($postData);

        //get profile type
        $postData['adapter'] = $profile->getType();

        $dataFactory = $this->Plugin()->getDataFactory();

        $dbAdapter = $dataFactory->createDbAdapter($profile->getType());
        $dataSession = $dataFactory->loadSession($postData);

        //create dataIO
        $dataIO = $dataFactory->createDataIO($dbAdapter, $dataSession);

        $colOpts = $dataFactory->createColOpts($postData['columnOptions']);
        $limit = $dataFactory->createLimit($postData['limit']);
        $filter = $dataFactory->createFilter($postData['filter']);
        $maxRecordCount = $postData['max_record_count'];
        $type = $postData['type'];
        $format = $postData['format'];

        $dataIO->initialize($colOpts, $limit, $filter, $type, $format, $maxRecordCount);

        $dataTransformerChain = $this->Plugin()->getDataTransformerFactory()->createDataTransformerChain(
                $profile, array('isTree' => $fileReader->hasTreeStructure())
        );

        $dataWorkflow = new DataWorkflow($dataIO, $profile, $dataTransformerChain, $fileReader);

        try {
            $post = $dataWorkflow->import($postData, $inputFile);

            return $this->View()->assign(array('success' => true, 'data' => $post));
        } catch (Exception $e) {
            return $this->View()->assign(array('success' => false, 'msg' => $e->getMessage()));
        }
    }

    public function getSessionsAction()
    {
        $sessionRepository = $this->getSessionRepository();

        $query = $sessionRepository->getSessionsListQuery(
                        $this->Request()->getParam('filter', array()), $this->Request()->getParam('sort', array()), $this->Request()->getParam('limit', 25), $this->Request()->getParam('start', 0)
                )->getQuery();

        $query->setHydrationMode(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

        $paginator = $this->getManager()->createPaginator($query);

        //returns the total count of the query
        $total = $paginator->count();

        //returns the customer data
        $data = $paginator->getIterator()->getArrayCopy();

        $this->View()->assign(array(
            'success' => true, 'data' => $data, 'total' => $total
        ));
    }

    /**
     * Deletes a single order from the database.
     * Expects a single order id which placed in the parameter id
     */
    public function deleteSessionAction()
    {
        try {
            $sessionId = (int) $this->Request()->getParam('id');

            if (empty($sessionId) || !is_numeric($sessionId)) {
                $this->View()->assign(array(
                    'success' => false,
                    'data' => $this->Request()->getParams(),
                    'message' => 'No valid Id')
                );
                return;
            }

            $entity = $this->getSessionRepository()->find($sessionId);
            $this->getManager()->remove($entity);

            //Performs all of the collected actions.
            $this->getManager()->flush();

            $this->View()->assign(array(
                'success' => true,
                'data' => $this->Request()->getParams())
            );
        } catch (Exception $e) {
            $this->View()->assign(array(
                'success' => false,
                'data' => $this->Request()->getParams(),
                'message' => $e->getMessage())
            );
        }
    }

    /**
     * Returns the shopware model manager
     *
     * @return Shopware\Components\Model\ModelManager
     */
    protected function getManager()
    {
        if ($this->manager === null) {
            $this->manager = Shopware()->Models();
        }
        return $this->manager;
    }

    public function uploadFileAction()
    {
        $this->Front()->Plugins()->Json()->setRenderer(false);

        $albumRepo = $this->getManager()->getRepository('Shopware\Models\Media\Album');

        $album = $albumRepo->findOneBy(array('name' => 'ImportFiles'));

        if (!$album) {
            $album = new Shopware\Models\Media\Album();
            $album->setName('ImportFiles');
            $album->setPosition(0);
            $this->getManager()->persist($album);
            $this->getManager()->flush($album);
        }

        $id = $album->getId();

        $this->Request()->setParam('albumID', $id);

        $this->forward('upload', 'mediaManager');
    }

    /**
     * Fires when the user want to open a generated order document from the backend order module.
     * @return Returns the created pdf file with an echo.
     */
    public function downloadFileAction()
    {
        try {
            $name = $this->Request()->getParam('fileName', null);


            $file = Shopware()->DocPath() . 'files/import_export/' . $name;

            //get file format
            $extension = pathinfo($file, PATHINFO_EXTENSION);

            switch ($extension) {
                case 'csv':
                    $application = 'text/csv';
                    break;
                case 'xml':
                    $application = 'application/xml';
                    break;
                default:
                    throw new \Exception('File extension is not valid');
            }

            if (!file_exists($file)) {
                $this->View()->assign(array(
                    'success' => false,
                    'data' => $this->Request()->getParams(),
                    'message' => 'File not exist'
                ));
            }

            $response = $this->Response();
            $response->setHeader('Cache-Control', 'public');
            $response->setHeader('Content-Description', 'File Transfer');
            $response->setHeader('Content-disposition', 'attachment; filename=' . $name);

            $response->setHeader('Content-Type', $application);
            readfile($file);
        } catch (\Exception $e) {
            $this->View()->assign(array(
                'success' => false,
                'data' => $this->Request()->getParams(),
                'message' => $e->getMessage()
            ));
            return;
        }

        Enlight_Application::Instance()->Events()->removeListener(new Enlight_Event_EventHandler('Enlight_Controller_Action_PostDispatch', ''));
    }

    public function getSectionsAction()
    {
        $postData['profileId'] = $this->Request()->getParam('profileId');

        if (!$postData['profileId']) {
            return $this->View()->assign(array(
                        'success' => false, 'message' => 'No profile Id'
            ));
        }

        $profile = $this->Plugin()->getProfileFactory()->loadProfile($postData);
        $type = $profile->getType();

        $dbAdapter = $this->Plugin()->getDataFactory()->createDbAdapter($type);
        
        $sections = $dbAdapter->getSections();
        
        $this->View()->assign(array(
            'success' => true, 
            'data' => $sections, 
            'total' => count($sections)
        ));
    }

    public function getColumnsAction()
    {
        $postData['profileId'] = $this->Request()->getParam('profileId');
        $section = $this->Request()->getParam('adapter', 'default');

        if (!$postData['profileId']) {
            return $this->View()->assign(array(
                        'success' => false, 'message' => 'No profile Id'
            ));
        }

        $profile = $this->Plugin()->getProfileFactory()->loadProfile($postData);
        $type = $profile->getType();

        $dbAdapter = $this->Plugin()->getDataFactory()->createDbAdapter($type);
        
        $columns = $dbAdapter->getColumns($section);
        
        if (!$columns || empty($columns)) {
            $this->View()->assign(array(
                'success' => false, 'msg' => 'No colums found.'
            ));
        }

        foreach ($columns as &$column) {
            $match = '';
            preg_match('/(?<=as ).*/', $column, $match);

            $match = trim($match[0]);

            if ($match != '') {
                $column = $match;
            } else {
                preg_match('/(?<=\.).*/', $column, $match);
                $match = trim($match[0]);
                if ($match != '') {
                    $column = $match;
                }
            }

            $column = array('id' => $column, 'name' => $column);
        }

        $this->View()->assign(array(
            'success' => true, 'data' => $columns, 'total' => count($columns)
        ));
    }

    /**
     * Check is file format valid
     * 
     * @param string $extension
     * @return boolean
     */
    public function isFormatValid($extension)
    {
        switch ($extension) {
            case 'csv':
            case 'xml':
                return true;
            default:
                return false;
        }
    }

    /**
     * Helper Method to get access to the profile repository.
     *
     * @return Shopware\Models\Category\Repository
     */
    public function getProfileRepository()
    {
        if ($this->profileRepository === null) {
            $this->profileRepository = $this->getManager()->getRepository('Shopware\CustomModels\ImportExport\Profile');
        }
        return $this->profileRepository;
    }

    /**
     * Helper Method to get access to the category repository.
     *
     * @return Shopware\Models\Category\Repository
     */
    public function getSessionRepository()
    {
        if ($this->sessionRepository === null) {
            $this->sessionRepository = $this->getManager()->getRepository('Shopware\CustomModels\ImportExport\Session');
        }
        return $this->sessionRepository;
    }

    /**
     * Helper Method to get access to the conversion repository.
     *
     * @return Shopware\Models\Category\Repository
     */
    public function getExpressionRepository()
    {
        if ($this->expressionRepository === null) {
            $this->expressionRepository = Shopware()->Models()->getRepository('Shopware\CustomModels\ImportExport\Expression');
        }
        return $this->expressionRepository;
    }

    public function Plugin()
    {
        return Shopware()->Plugins()->Backend()->SwagImportExport();
    }

}
