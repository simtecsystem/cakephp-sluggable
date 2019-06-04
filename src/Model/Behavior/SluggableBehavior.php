<?php

namespace Sluggable\Model\Behavior;

use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Sluggable\Utility\Slug;

/**
 * @package default
 */

class SluggableBehavior extends Behavior
{
    
    /**
     * Array of default config options. Will be available from $this->config()
     * @param field (string) the field we will be slugging
     * @param slug (string) the field storing the slug
     * @param replacement (string) what to replace spaces and stuff with
     */
    protected $_defaultConfig = [
        'pattern'       => ':name',
        'field'         => 'slug',
        'replacement'   => '-',
        'overwrite'     => false,
        ];

    protected function _generateSlugForGeneralEntity(Entity $entity) {
        $config = $this->getConfig();

        if(!$entity->isNew() && !empty($entity->get($config['field']))) {
            return $entity->get($config['field']);
        }

        // i want to create the following slug.
        $defaultSlugName = Slug::generate($config['pattern'], $entity, $config['replacement']);

        // checks suffix already there, if so then skip
        $suffix = str_replace( $defaultSlugName . '-', '', $entity->get( $config['field'] ) );

        if (is_numeric($suffix)) {
            return $entity->get($config['field']);
        }

        /// only add a suffix if the table has more then one entry with the same slug ///
        $customWhere = [];

        // customWhere in order to exclude itself from the count below
        if(is_array($this->getTable()->getPrimaryKey()) ) {
            foreach($this->getTable()->getPrimaryKey() as $pk) {
                $customWhere[$pk] = $entity->get($pk);
            }
        } else {
            $customWhere[ $this->getTable()->getPrimaryKey() ] = $entity->get($this->getTable()->getPrimaryKey());
        }

        $condition = [
            $config['field'] => $defaultSlugName,
            "NOT" => $customWhere
        ];

        $count = $this->getTable()
            ->find()
            ->where($condition)
            ->count();

        if ($count > 0) {

            // count of rows with slug-field having a -number suffix
            $maxCount = $this->getTable()
                ->find()
                ->select(
                    [
                        'slug_count' => 'REPLACE(' . $config['field'] . ', "' . $defaultSlugName . '-", "")'
                    ]
                )
                ->where(
                    [
                        $config['field'] . ' RLIKE' => $defaultSlugName . '-[0-9]+'
                    ]
                )
                ->order(
                    [
                        'slug_count' => 'ASC'
                    ]
                )
                ->count();

            // for same named slugs
            // start counting from 2
            // first: slug
            // second: slug-2
            if ($maxCount === 0) {
                $defaultSlugName .= "-" . 2;
            } else {
                $defaultSlugName .= "-" . ($maxCount + 2);
            }

        }

        return $defaultSlugName;
    }

    protected function _generateSlugForRevisionableEntity(Entity $entity) {
        $config = $this->getConfig();

        if(!$entity->isNew()) {
            return $entity->get($config['field']);
        }

        // i want to create the following slug.
        $defaultSlugName = Slug::generate($config['pattern'], $entity, $config['replacement']);

        // checks suffix already there, if so then skip
        $suffix = str_replace( $defaultSlugName . '-', '', $entity->get( $config['field'] ) );

        if (is_numeric($suffix)) {
            return $entity->get($config['field']);
        }

        // only add a suffix if the table has more then one entry with the same slug
        $customWhere = [];

        if(is_array($this->getTable()->getPrimaryKey()) ) {
            foreach($this->getTable()->getPrimaryKey() as $pk) {
                if($pk == "revision") continue;

                $customWhere[$pk] = $entity->get($pk);
            }
        } else {
            $customWhere[$this->getTable()->getPrimaryKey()] = $entity->get($this->getTable()->getPrimaryKey());
        }

        $condition = [
            $config['field'] => $defaultSlugName,
            "valid_until" => "0000-00-00 00:00:00",
            "NOT" => $customWhere
        ];

        $count = $this->getTable()
            ->find()
            ->where($condition)
            ->count();

        if ($count > 0) {

            // count of rows with slug-field having a -number suffix
            $maxCount = $this->getTable()
                ->find()
                ->select(
                    [
                        'slug_count' => 'REPLACE(' . $config['field'] . ', "' . $defaultSlugName . '-", "")'
                    ]
                )
                ->where(
                    [
                        $config['field'] . ' RLIKE' => $defaultSlugName . '-[0-9]+'
                    ]
                )
                ->order(
                    [
                        'slug_count' => 'ASC'
                    ]
                )
                ->count();

            // for same named slugs
            // start counting from 2
            // first: slug
            // second: slug-2
            if ($maxCount === 0) {
                $defaultSlugName .= "-" . 2;
            } else {
                $defaultSlugName .= "-" . ($maxCount + 2);
            }

        }

        return $defaultSlugName;
    }

    /**
     * Uses the configuration settings to generate a slug for the given $entity.
     * @param Entity $entity
     * @return string slug
     */
    private function _generateSlug(Entity $entity)
    {
        $config = $this->getConfig();                                  # load the config built by the instantiated behavior
        if ($entity->get($config['field']) && !$config['overwrite']) :    # if already set, and !overwrite
            return $entity->get($config['field']);                  # return existing
        endif;

        $usedTraits = class_uses($entity);
        if(in_array('TecBase\Revisionable\RevisionEntityTrait', $usedTraits)) {
            return $this->_generateSlugForRevisionableEntity($entity);
        } else {
            return $this->_generateSlugForGeneralEntity($entity);
        }                                            # return the slug
    }

    /**
     * Before Saving the entity, slug it.
     * @param Event $event
     * @param Entity $entity
     * @return mixed
     */
    public function afterSave(Event $event, Entity $entity, $options)
    {
        $config = $this->getConfig();                                  # load the config built by the instantiated behavior


        $original = $entity->get($config['field']);                 # manually store $original - wasn't working for some reason otherwise

        $usedTraits = class_uses($entity);

        if(in_array('TecBase\Revisionable\RevisionEntityTrait', $usedTraits)) {
            // if old revision, skip change of slug
            $entity->set($config['field'], $this->_generateSlug($entity)); # set the slug

            # if the slug is actually different than before - save it
            if ($entity->isDirty() && ($original != $entity->get($config['field']))) :
                $this->_table->hardSave($entity);
            endif;
            return true;
        }

        $entity->set($config['field'], $this->_generateSlug($entity)); # set the slug

        # if the slug is actually different than before - save it
        if ($entity->isDirty() && ($original != $entity->get($config['field']))) :
            $this->_table->save($entity);
        endif;

    }

    /**
     * Allows you to do a $table->find('slugged', ['slug'=>'hello-world'])
     * @param Query $query
     * @param array $options
     * @return Query
     */
    public function findSlugged(Query $query, array $options)
    {
        $config = $this->getConfig();
        return $query->where([$this->_table->alias().'.'.$config['field'] => $options['slug']]);
    }

    /**
     * Allows you to do a $table->find('sluggedList') and get an array of [slug]=>name instead of id=>name
     * @param Query $query
     * @param array $options
     * @return Query
     */
    public function findSluggedList(Query $query, array $options)
    {
        $config = $this->getConfig();
        return $query->find('list', ['keyField'=>$config['field']]);
    }
}
