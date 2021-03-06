<?php

namespace Tests\Serializer;

use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;
use Uploadcare\File\ImageInfo;
use Uploadcare\Interfaces\SerializableInterface;
use Uploadcare\Interfaces\Serializer\SerializerInterface;
use Uploadcare\Serializer\Exceptions\ConversionException;
use Uploadcare\Serializer\Exceptions\SerializerException;
use Uploadcare\Serializer\Serializer;
use Uploadcare\Serializer\SnackCaseConverter;

class DeserializerTest extends TestCase
{
    /**
     * @var Generator
     */
    private $faker;

    protected function setUp()
    {
        $this->faker = Factory::create();
    }

    /**
     * @return SerializerInterface
     */
    protected function getSerializer()
    {
        return new Serializer(new SnackCaseConverter());
    }

    /**
     * @param array $additionalData
     *
     * @return string JSON with image_info data
     */
    protected function getImageInfoJson(array $additionalData = [])
    {
        $data = \json_decode(\file_get_contents(\dirname(__DIR__) . '/_data/file-info.json'), true);
        $imageInfo = $data['image_info'];
        foreach ($additionalData as $key => $value) {
            $imageInfo[$key] = $value;
        }

        return \json_encode($imageInfo);
    }

    public function testDenormalizeImageInfo()
    {
        $serializer = $this->getSerializer();

        $lon = $this->faker->longitude;
        $lat = $this->faker->latitude;
        $date = \date_create();

        $imageInfo = $this->getImageInfoJson([
            'sequence' => true,
            'geo_location' => [
                'latitude' => $lat,
                'longitude' => $lon,
            ],
            'datetime_original' => $date->format('Y-m-d\TH:i:s.u\Z'),
        ]);

        /** @var ImageInfo $object */
        $object = $serializer->deserialize($imageInfo, ImageInfo::class);

        self::assertInstanceOf(ImageInfo::class, $object);

        self::assertEquals('RGB', $object->getColorMode());
        self::assertNull($object->getOrientation());
        self::assertEquals('JPEG', $object->getFormat());
        self::assertTrue($object->isSequence());
        self::assertEquals(500, $object->getHeight());
        self::assertEquals(800, $object->getWidth());
        self::assertEquals($lat, $object->getGeoLocation()->getLatitude());
        self::assertEquals($lon, $object->getGeoLocation()->getLongitude());
        self::assertInstanceOf(\DateTimeInterface::class, $object->getDatetimeOriginal());
        self::assertEquals([144, 144], $object->getDpi());
    }

    public function testNotSerializableClass()
    {
        $this->expectException(SerializerException::class);

        $message = \sprintf('Class \'%s\' must implements the \'%s\' interface', \DateTimeInterface::class, SerializableInterface::class);
        $this->getSerializer()->deserialize(\json_encode(\date_create()), \DateTime::class);
        $this->expectExceptionMessageRegExp($message);
    }

    public function testUnableToDecode()
    {
        $this->expectException(ConversionException::class);

        $this->getSerializer()->deserialize(\date_create()->format(DATE_ATOM));
        $this->expectExceptionMessageRegExp('Unable to decode given value. Error');
    }
}
