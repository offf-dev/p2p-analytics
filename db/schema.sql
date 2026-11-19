CREATE TABLE IF NOT EXISTS deals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  deal_date DATE NOT NULL,
  deal_time TIME NULL,
  direction ENUM('UAH_USDT', 'USDT_THB') NOT NULL,
  exchange ENUM('Binance', 'Bybit', 'BingX') NOT NULL,
  amount_from DECIMAL(18, 8) NOT NULL,
  amount_to DECIMAL(18, 8) NOT NULL,
  rate DECIMAL(18, 8) NOT NULL,
  declared_rate DECIMAL(18, 8) NULL,
  counterparty VARCHAR(100) NULL,
  note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_deal_date (deal_date),
  INDEX idx_direction (direction),
  INDEX idx_exchange (exchange)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nbu_rates (
  rate_date DATE NOT NULL,
  currency VARCHAR(3) NOT NULL,
  rate_uah DECIMAL(18, 6) NOT NULL,
  fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (rate_date, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;