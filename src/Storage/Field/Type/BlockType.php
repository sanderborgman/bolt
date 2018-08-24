<?php
namespace Bolt\Storage\Field\Type;

use Bolt\Storage\Field\Collection\RepeatingFieldCollection;

/**
 * This class adds a block collection and handles additional functionality for adding
 * named blocks.
 *
 * @author Ross Riley <riley.ross@gmail.com>
 */
class BlockType extends RepeaterType
{
    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'block';
    }

    /**
     * Normalize step ensures that we have correctly hydrated objects at the collection
     * and entity level.
     *
     * @param $entity
     */
    public function normalize($entity)
    {
        $key = $this->mapping['fieldname'];
        $accessor = 'get' . ucfirst($key);

        $outerCollection = $entity->$accessor();
        if (!$outerCollection instanceof RepeatingFieldCollection) {
            $collection = new RepeatingFieldCollection($this->em, $this->mapping);
            $collection->setName($key);

            if (is_array($outerCollection)) {
                foreach ($outerCollection as $group => $block) {
                    foreach ($block as $blockName => $fields) {
                        if (is_array($fields)) {
                            unset($fields['__internal']);
                            $collection->addFromArray($fields, $group, $entity, $blockName);
                        }
                    }
                }
            }

            $setter = 'set' . ucfirst($key);
            $entity->$setter($collection);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hydrate($data, $entity)
    {
        /** @var string $key */
        $key = $this->mapping['fieldname'];
        $collection = new RepeatingFieldCollection($this->em, $this->mapping);
        $collection->setName($key);

        // If there isn't anything set yet then we just return an empty collection
        if (!isset($data[$key])) {
            $this->set($entity, $collection);

            return;
        }

        // This block separately handles JSON content for Templatefields
        if (isset($data[$key]) && Json::test($data[$key])) {
            $originalMapping[$key]['fields'] = $this->mapping['fields'];
            $originalMapping[$key]['type'] = 'block';
            $mapping = $this->em->getMapper()->getRepeaterMapping($originalMapping);

            $decoded = Json::parse($data[$key]);
            $collection = new RepeatingFieldCollection($this->em, $mapping);
            $collection->setName($key);

            if (isset($decoded) && count($decoded)) {
                foreach ($decoded as $group => $block) {
                    if (!is_array($block)) continue;

                    foreach ($block as $blockName => $fields) {
                        if (is_array($fields)) {
                            unset($fields['__internal']);
                            $collection->addFromArray($fields, $group, null, $blockName);
                        }
                    }
                }
            }

            $this->set($entity, $collection);

            return;
        }

        // Final block handles values stored in the DB and creates a lazy collection
        $vals = array_filter(explode(',', $data[$key]));
        $values = [];
        foreach ($vals as $fieldKey) {
            $split = explode('_', $fieldKey);
            $id = array_pop($split);
            $group = array_pop($split);
            $field = implode('_', $split);
            $values[$field][$group][] = $id;
        }

        if (isset($values[$key]) && count($values[$key])) {
            ksort($values[$key]);
            foreach ($values[$key] as $group => $refs) {
                $collection->addFromReferences($refs, $group);
            }
        }

        $this->set($entity, $collection);
    }
}
