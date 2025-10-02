# 不同付款方式的 webhook 流程

## 沙盒環境的限制

> 金額為3的倍數的交易，則會固定進入 3D 流程；
> 金額為非 3 的整數倍的交易固定進入非 3D 交易流程。
> 非 3D 交易流程情況下，去掉 TWD 最小單位的 00 後的金額，若金額為單數的，交易會成功。若金額為雙數的，交易會失敗。

## 信用卡流程(非3D)

```php
// 成功
session.succeeded
↓
trade.succeeded



// 失敗
trade.failed // 直接失敗 ex 發卡銀行拒絕

// 過期
session.expired
```

## 信用卡流程(3D)

```php
// 成功
trade.customer_action
↓
session.pending // 簡訊 3D 驗證
↓
trade.succeeded
↓
session.succeeded


// 失敗
trade.customer_action
↓
session.pending
↓
trade.failed

```

## ATM 虛擬帳號

## 街口

```php
// 成功
trade.customer_action
↓
session.pending
↓
trade.succeeded | trade.failed
↓
session.succeeded


// 失敗
trade.failed
```

## 銀角

```php
// 成功
trade.customer_action
↓
session.pending
↓
trade.succeeded | trade.failed
↓
session.succeeded


// 失敗
session.pending
↓
trade.customer_action
↓
trade.failed
```