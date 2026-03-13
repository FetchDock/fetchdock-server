<?php

namespace App\Tests\Unit\Entity;

use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class OidcSubjectIdentifierTest extends TestCase
{
    public function testGettersAndSetters()
    {
        $subjectIdentifier = new OidcSubjectIdentifier();
        $subjectIdentifier->setSubject('test_subject');
        $this->assertEquals('test_subject', $subjectIdentifier->getSubject());
    }

    public function testDownloadJobCollectionRelation()
    {
        $downloadJobOne = new DownloadJob();
        $downloadJobTwo = new DownloadJob();

        $subjectIdentifier = new OidcSubjectIdentifier();
        $subjectIdentifier->addDownloadJob($downloadJobOne);
        $subjectIdentifier->addDownloadJob($downloadJobTwo);

        $this->assertInstanceOf(ArrayCollection::class, $subjectIdentifier->getDownloadJobs());
        $this->assertContains($downloadJobOne, $subjectIdentifier->getDownloadJobs());
        $this->assertContains($downloadJobTwo, $subjectIdentifier->getDownloadJobs());

        $this->assertCount(2, $subjectIdentifier->getDownloadJobs());

        $this->assertEquals($downloadJobOne, $subjectIdentifier->getDownloadJobs()->first());
        $this->assertEquals($downloadJobTwo, $subjectIdentifier->getDownloadJobs()->last());

        $subjectIdentifier->removeDownloadJob($downloadJobOne);
        $this->assertCount(1, $subjectIdentifier->getDownloadJobs());
        $this->assertNotContains($downloadJobOne, $subjectIdentifier->getDownloadJobs());
        $this->assertContains($downloadJobTwo, $subjectIdentifier->getDownloadJobs());
    }

}
