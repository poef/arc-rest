<?php
	$this->delete();

	$tree = [
		'/' => object([
			'name' => 'Root',
			'type' => 'root'
		]),
		'/muze/' => object([
			'type' => 'tenant',
			'name' => 'Muze'			
		]),
		'/muze/users/' => object([
			'type' => 'folder',
			'name' => 'users'
		]),
		'/muze/roles/' => object([
			'type' => 'folder',
			'name' => 'roles'
		]),
		'/muze/clients/' => object([
			'type' => 'folder',
			'name' => 'clients'
		])
	];

	\arc\tree::map(\arc\tree::expand($tree), function($node) {
		$this->data->type = $node->nodeValue->type;
		$this->data->name = $node->nodeValue->name;
		$this->path = $node->getPath();
		echo "<br>".$this->path." ";
		if (!$this->save()) {
			echo 'Failed.';
			die();
		} else {
			echo 'Ok.';
		}
	});