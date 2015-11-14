<?php
/**
 * PostmanMessageQuery.php
 * @author Revin Roman
 * @link https://rmrevin.com
 */

namespace cookyii\modules\Postman\resources\queries;

/**
 * Class PostmanMessageQuery
 * @package cookyii\modules\Postman\resources\queries
 *
 * @method \cookyii\modules\Postman\resources\PostmanMessage|array|null one($db = null)
 * @method \cookyii\modules\Postman\resources\PostmanMessage[]|array all($db = null)
 */
class PostmanMessageQuery extends \yii\db\ActiveQuery
{

    use \cookyii\db\traits\query\DeletedQueryTrait;

    /**
     * @param integer|array $id
     * @return static
     */
    public function byId($id)
    {
        $this->andWhere(['id' => $id]);

        return $this;
    }

    /**
     * @return static
     */
    public function onlyNotSent()
    {
        $this->andWhere(['sent_at' => null]);

        return $this;
    }

    /**
     * @param string $query
     * @return static
     */
    public function search($query)
    {
        $words = explode(' ', $query);

        $this->andWhere([
            'or',
            array_merge(['or'], array_map(function ($value) { return ['like', 'id', $value]; }, $words)),
            array_merge(['or'], array_map(function ($value) { return ['like', 'subject', $value]; }, $words)),
            array_merge(['or'], array_map(function ($value) { return ['like', 'content_text', $value]; }, $words)),
            array_merge(['or'], array_map(function ($value) { return ['like', 'content_html', $value]; }, $words)),
            array_merge(['or'], array_map(function ($value) { return ['like', 'address', $value]; }, $words)),
        ]);

        return $this;
    }
}