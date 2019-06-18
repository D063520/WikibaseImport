<?php

namespace Wikibase\Import;

use Psr\Log\LoggerInterface;
use User;
use Wikibase\DataModel\Entity\BasicEntityIdParser;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\EntityContent;
use Wikibase\Import\Store\ImportedEntityMappingStore;
use Wikibase\Lib\Store\EntityStore;
use Wikibase\Repo\WikibaseRepo;
use DataValues\StringValue;
use DataValues\UnboundedQuantityValue;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;

class EntityImporter
{

    private $statementsImporter;

    private $badgeItemUpdater;

    private $apiEntityLookup;

    private $entityStore;

    private $entityMappingStore;

    private $logger;

    private $statementsCountLookup;

    private $idParser;

    private $importUser;

    private $batchSize;

    public function __construct(
        StatementsImporter $statementsImporter,
        BadgeItemUpdater $badgeItemUpdater,
        ApiEntityLookup $apiEntityLookup,
        EntityStore $entityStore,
        ImportedEntityMappingStore $entityMappingStore,
        StatementsCountLookup $statementsCountLookup,
        LoggerInterface $logger
    )
    {
        $this->statementsImporter = $statementsImporter;
        $this->badgeItemUpdater = $badgeItemUpdater;
        $this->apiEntityLookup = $apiEntityLookup;
        $this->entityStore = $entityStore;
        $this->entityMappingStore = $entityMappingStore;
        $this->statementsCountLookup = $statementsCountLookup;
        $this->logger = $logger;

        $this->idParser = new BasicEntityIdParser();
        $this->importUser = User::newFromId(0);
        $this->batchSize = 10;

        //create a new property for external links to Wikidata called Wikidata ID
        try {
            $entity = new Property(null, null, 'external-id');
            $entity->setLabel("en", "Wikidata ID");
            $revision = $this->entityStore->saveEntity($entity, 'Import entity', $this->importUser, EDIT_NEW);
            echo "here";
            $wikidataPropertyId = $revision->getEntity()->getId();
            //print_r($localId);
            $this->entityMappingStore->add(new PropertyId('P1000000'), $wikidataPropertyId);
        } catch (\Exception $ex) {
            $this->logger->info("Wikidata ID property already existing");
            //$pattern = "/\|.*]]/";
            //preg_match($pattern, $ex->getMessage(), $match);
            //$this->idWikidataProperty = str_replace("]","",str_replace("|","",$match[0]));
            $this->logger->error($ex->getMessage());
        }

    }

    public function importEntities(array $ids, $importStatements = true)
    {
        $batches = array_chunk($ids, $this->batchSize);

        $stashedEntities = array();

        //print_r($batches);
        foreach ($batches as $batch) {
            $batch_new = [];
            //search if the entity or property was already instered in the Wiki
            foreach ($batch as $key => $id) {
                $newId = NULL;
                if (substr($id, 0, 1) == 'Q') {
                    $newId = $this->entityMappingStore->getLocalId(new ItemId($id));
                }
                if (substr($id, 0, 1) == 'P') {
                    $newId = $this->entityMappingStore->getLocalId(new PropertyId($id));
                }
                if ($newId == NULL || $newId == '') {
                    array_push($batch_new, $id);
                }
                if ($importStatements == true) {
                    array_push($batch_new, $id);
                }
            }
            //print_r($batches_new);
            $entities = $this->apiEntityLookup->getEntities($batch_new);
            //print_r($entities);
            if ($entities) {
                $this->importBadgeItems($entities);
            } else {
                if (count($batch_new) != 0) {
                    $this->logger->error('Failed to retrieve items for batch');
                }
            }
            $stashedEntities = array_merge($stashedEntities, $this->importBatch($batch_new));
        }

        if ($importStatements === true) {
            //$stashedEntities = array_merge($stashedEntities, $this->importBatch($batch));
            foreach ($stashedEntities as $entity) {
                $localId = $this->entityMappingStore->getLocalId($entity->getId());
                echo $this->statementsCountLookup->getStatementCount($localId);
                if ($localId && $this->statementsCountLookup->getStatementCount($localId) < 2) {
                    $referencedEntities = $this->getReferencedEntities($entity);
                    $this->importEntities($referencedEntities, false);

                    $entity_new = $entity;
                    $statements_new = $entity->getStatements();
                    $uri = WikibaseRepo::getDefaultInstance()->getSettings()->getSetting('conceptBaseUri');
                    foreach ($statements_new as $key1 => $statement_new) {
                        $snak_new = $statement_new->getMainSnak();
                        if ($snak_new instanceof PropertyValueSnak) {
                            $data_value_new = $snak_new->getDataValue();
                            if ($data_value_new instanceof UnboundedQuantityValue) {
                                $unit = $data_value_new->getUnit();
                                if (strpos($unit, 'http://www.wikidata.org/entity/') !== false) {
                                    $id = str_replace("http://www.wikidata.org/entity/", "", $unit);
                                    $newid = $this->entityMappingStore->getLocalId(new ItemId($id));
                                    $data_value_new = new UnboundedQuantityValue($data_value_new->getAmount(), $uri . $newid);
                                    $snak_new = new PropertyValueSnak($snak_new->getPropertyId(), $data_value_new);
                                    $statement_new->setMainSnak($snak_new);
                                }
                            }
                        }
                        $snakList_new = $statement_new->getQualifiers();
                        foreach ($snakList_new as $key2 => $snak_new) {
                            if ($snak_new instanceof PropertyValueSnak) {
                                $data_value_new = $snak_new->getDataValue();
                                if ($data_value_new instanceof UnboundedQuantityValue) {
                                    $unit = $data_value_new->getUnit();
                                    if (strpos($unit, 'http://www.wikidata.org/entity/') !== false) {
                                        $id = str_replace("http://www.wikidata.org/entity/", "", $unit);
                                        $newid = $this->entityMappingStore->getLocalId(new ItemId($id));
                                        $data_value_new = new UnboundedQuantityValue($data_value_new->getAmount(), $uri . $newid);
                                        $snak_new = new PropertyValueSnak($snak_new->getPropertyId(), $data_value_new);
                                    }
                                }
                            }
                            $snakList_new[$key2] = $snak_new;
                        }
                    }
                    $entity->getStatements()->addStatement(new Statement(new PropertyValueSnak(new PropertyId('P1000000'), new StringValue((string)$entity->getId())), null, null, null));
                    $this->statementsImporter->importStatements($entity_new);
                } else {
                    $this->logger->info(
                        'Statements already imported for ' . $entity->getId()->getSerialization()
                    );
                }
            }
        } else {
            foreach ($stashedEntities as $entity) {
                $entity->setStatements(new StatementList());
                $entity->getStatements()->addStatement(new Statement(new PropertyValueSnak(new PropertyId('P1000000'), new StringValue((string)$entity->getId())), null, null, null));
                $this->statementsImporter->importStatements($entity);
            }
        }
    }

    private function importBatch(array $batch)
    {
        $entities = $this->apiEntityLookup->getEntities($batch);

        if (!is_array($entities)) {
            $this->logger->error('Failed to import batch');

            return array();
        }

        $stashedEntities = array();

        foreach ($entities as $originalId => $entity) {
            $stashedEntities[] = $entity->copy();
            $originalEntityId = $this->idParser->parse($originalId);

            if (!$this->entityMappingStore->getLocalId($originalEntityId)) {
                try {
                    $this->logger->info("Creating $originalId");

                    $entityRevision = $this->createEntity($entity);
                    $localId = $entityRevision->getEntity()->getId();
                    $this->entityMappingStore->add($originalEntityId, $localId);
                } catch (\Exception $ex) {
                    $this->logger->error("Failed to add $originalId");
                    $this->logger->error($ex->getMessage());
                }
            } else {
                $this->logger->info("$originalId already imported");
            }
        }

        return $stashedEntities;
    }

    private function createEntity(EntityDocument $entity)
    {
        $wikidataId = $entity->getId();
        $entity->setId(null);

        $entity->setStatements(new StatementList());

        //Adds external link to wikidata
        //$wikidataPropertyId = $this->entityMappingStore-> getLocalId(new PropertyId('P1000000'));
        //$entity->getStatements()->addStatement(new Statement(new PropertyValueSnak(new PropertyId((string)$wikidataPropertyId), new StringValue( (string)$wikidataId )),null,null,null));
        if ($entity instanceof Item) {
            $siteLinkList = $this->badgeItemUpdater->replaceBadges($entity->getSiteLinkList());
            $entity->setSiteLinkList($siteLinkList);
        }

        return $this->entityStore->saveEntity(
            $entity,
            'Import entity',
            $this->importUser,
            EDIT_NEW | EntityContent::EDIT_IGNORE_CONSTRAINTS
        );
    }

    private function getBadgeItems(array $entities)
    {
        $badgeItems = array();

        foreach ($entities as $entity) {
            if (!$entity instanceof Item) {
                continue;
            }

            foreach ($entity->getSiteLinks() as $siteLink) {
                foreach ($siteLink->getBadges() as $badge) {
                    $badgeItems[] = $badge->getSerialization();
                }
            }
        }

        return $badgeItems;
    }

    private function getReferencedEntities(EntityDocument $entity)
    {
        $statements = $entity->getStatements();
        $snaks = $statements->getAllSnaks();
        $entities = array();

        foreach ($snaks as $key => $snak) {
            $entities[] = $snak->getPropertyId()->getSerialization();

            if ($snak instanceof PropertyValueSnak) {
                $value = $snak->getDataValue();
                if ($value instanceof EntityIdValue) {
                    $entities[] = $value->getEntityId()->getSerialization();
                }
                if ($value instanceof UnboundedQuantityValue) {
                    $unit = $value->getUnit();
                    if (strpos($unit, 'http://www.wikidata.org/entity/') !== false) {
                        $value2 = array_pop(array_reverse($this->apiEntityLookup->getEntities([str_replace("http://www.wikidata.org/entity/", "", $value->getUnit())])));
                        $number = $value2->getId()->getSerialization();
                        $unit = $value->getUnit();
                        $unit = $number;
                        $entities[] = $number;
                    }
                }
            }
        }
        return array_unique($entities);
    }

    private function importBadgeItems(array $entities)
    {
        $badgeItems = $this->getBadgeItems($entities);
        $this->importEntities($badgeItems, false);
    }

}
