<?php
declare(strict_types=1);

// Durable sessions shared by every production container. The raw cookie ID is
// never stored in MySQL. A separate connection keeps session locks independent
// from tournament transactions and releases locks on process termination.
final class DatabaseSessions implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private PDO $connection;
    private ?string $lock=null;
    public function __construct(PDO $connection) { $this->connection=$connection; }
    private function key(string $id): string { return hash('sha256',$id); }
    private function acquire(string $id): void {
        $name='tf_sess_'.substr($this->key($id),0,48);
        if ($this->lock===$name) return;
        $this->close();
        if ((int)query($this->connection,'SELECT GET_LOCK(?,10)',[$name])->fetchColumn()!==1) throw new RuntimeException('Session store is busy.');
        $this->lock=$name;
    }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool {
        if ($this->lock!==null) {
            try { query($this->connection,'SELECT RELEASE_LOCK(?)',[$this->lock]); }
            finally { $this->lock=null; }
        }
        return true;
    }
    public function validateId(string $id): bool {
        return (bool)one($this->connection,'SELECT id_hash FROM web_sessions WHERE id_hash=? AND expires_at>?',[$this->key($id),time()]);
    }
    public function read(string $id): string|false {
        $this->acquire($id);
        $row=one($this->connection,'SELECT payload FROM web_sessions WHERE id_hash=? AND expires_at>?',[$this->key($id),time()]);
        return $row ? (string)$row['payload'] : '';
    }
    public function write(string $id, string $data): bool {
        $this->acquire($id);
        query($this->connection,'INSERT INTO web_sessions(id_hash,payload,expires_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)',[$this->key($id),$data,time()+1800]);
        return true;
    }
    public function updateTimestamp(string $id, string $data): bool { return $this->write($id,$data); }
    public function destroy(string $id): bool {
        $this->acquire($id);
        query($this->connection,'DELETE FROM web_sessions WHERE id_hash=?',[$this->key($id)]);
        return true;
    }
    public function gc(int $max_lifetime): int|false {
        return query($this->connection,'DELETE FROM web_sessions WHERE expires_at<? LIMIT 1000',[time()])->rowCount();
    }
}
