<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

interface Repository
{
    public function find(array $where = [], ?string $order = null, ?int $count = null, int $offset = 0, ?string $groupBy = null, array $columns = ['*']): array;

    /**
     * C in CRUD
     *
     * @param array $data
     * @return boolean|int
     */
    public function create(array $data);

    /**
     * U in CRUD
     *
     * @param array $data
     * @param array $where
     * @return boolean
     */
    public function update($data, $where);

    /**
     * D in CRUD
     *
     * @param array $id
     * @return int number of rows deleted
     */
    public function delete($where);

    public function deleteWhere(array $where = []);

    public function get($id): array;

    public function beginTransaction();

    public function commit();

    public function rollback();
}