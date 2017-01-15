<?php

namespace AppBundle\Indexation\Talks;

use AlgoliaSearch\Client;
use AppBundle\Event\Model\Planning;
use AppBundle\Event\Model\Repository\EventRepository;
use AppBundle\Event\Model\Repository\PlanningRepository;
use AppBundle\Event\Model\Repository\SpeakerRepository;
use AppBundle\Event\Model\Repository\TalkRepository;
use CCMBenchmark\TingBundle\Repository\RepositoryFactory;

class Runner
{
    /**
     * @var Client
     */
    protected $algoliaClient;

    /**
     * @var RepositoryFactory
     */
    protected $ting;

    /**
     * @var Transformer
     */
    protected $transformer;

    /**
     * @param Client $algoliaClient
     * @param RepositoryFactory $ting
     */
    public function __construct(Client $algoliaClient, RepositoryFactory $ting)
    {
        $this->algoliaClient = $algoliaClient;
        $this->ting = $ting;
        $this->transformer = new Transformer();
    }

    /**
     *
     */
    public function run()
    {
        $index = $this->initIndex();

        $objects = [];

        foreach ($this->getAllPlannings() as $planning) {

            if (null === ($object = $this->prepareObject($planning))) {
                continue;
            }

            $objects[] = $object;
        }

        $index->addObjects($objects, 'planning_id');
    }

    /**
     * @return \AlgoliaSearch\Index
     */
    protected function initIndex()
    {
        $index = $this->algoliaClient->initIndex('afup_talks');

        $index->setSettings([
            'attributesForFaceting' => [
                'event.title',
                'speakers.label',
                'has_video',
                'has_slides',
            ],
            'customRanking' => [
                "desc(event.start_date)",
                "desc(has_video)",
                "desc(has_blog_post)",
                "desc(has_slides)",
            ],
            'searchableAttributes' => [
                'title',
                'speakers.label',
                'event.title',
            ],
        ]);

        return $index;
    }

    /**
     * @return Planning[]
     */
    protected function getAllPlannings()
    {
        return $this->ting->get(PlanningRepository::class)->getAll();
    }

    /**
     * @param Planning $planning
     *
     * @return array|null
     */
    protected function prepareObject(Planning $planning)
    {
        $talk = $this->ting->get(TalkRepository::class)->get($planning->getTalkId());

        if (null === $talk) {
            return  null;
        }

        $event = $this->ting->get(EventRepository::class)->get($planning->getEventId());

        if (null === $event) {
            return null;
        }

        $speakers = $this->ting->get(SpeakerRepository::class)->getSpeakersByTalk($talk);

        return $this->transformer->transform($planning, $talk, $event, $speakers);
    }
}
