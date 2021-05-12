<?php

class SecureStore {
	use \arc\traits\Proxy;

	public function __construct($grantsTree, $store) {
		$this->target = $store;
		$this->grantsTree = $grantsTree;
	}

	public function cd($path) {
		return new SecureStore($this->grantsTree->cd($path), $this->target->cd($path));
	}

	public function find($query, $path='') {
		if (!$this->grantsTree->cd($path)->check('read')) {
			throw new \arc\AuthenticationError('Access denied');
		}
		return $this->filterGrant( $this->target->find($query, $path), 'read');
	}

	public function get($path='') {
		if (!$this->grantsTree->cd($path)->check('read')) {
			var_dump($this->grantsTree);
			throw new \arc\AuthenticationError('Access denied');
		}
		return $this->target->get($path);
	}

	public function ls($path='') {
		return $this->filterGrant( $this->target->ls($path), 'read' );	
	}

	public function parents($path='', $top='/') {
		if (!$this->grantsTree->cd($path)->check('read')) {
			throw new \arc\AuthenticationError('Access denied');
		}
		return $this->filterGrant(
            $this->target->parents($path, $top),
            "read"
        );			
	}

	public function exists($path='') {
		if (!$this->grantsTree->cd($path)->check('read')) {
			throw new \arc\AuthenticationError('Access denied');
		}
		return $this->target->exists($path);	
	}

	public function save($data, $path='') {
		if (!$this->target->exists($path)) {
			// new object, so use create grant
			if (!$this->grantsTree->cd($path)->check('create')) {
				throw new \arc\AuthenticationError('Access denied');
			}
		} else {
			// existing object, so user update grant
			if (!$this->grantsTree->cd($path)->check('update')) {
				throw new \arc\AuthenticationError('Access denied');
			}
		}
		return $this->target->save($data, $path);
	}

	public function delete($path='') {
		if (!$this->grantsTree->cd($path)->check('delete')) {
			throw new \arc\AuthenticationError('Access denied');
		}
		return $this->target->delete($path);
	}

	public function checkGrant($node, $grant) {
	    return $this->grantsTree->cd($node->path)->check($grant);
	}

	public function checkRead($node) {
		return $this->checkGrant($node, 'read');
	}

	public function filterGrant($nodes, $grant) {
		if (!$nodes) {
			yield $nodes;
		}
		foreach($nodes as $node) {
			if ($this->checkGrant($node, $grant)) {
				yield $node;
			}
		}
	}

}