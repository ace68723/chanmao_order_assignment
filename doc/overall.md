
#### cm_order_update

Request:

|  Parameter   | type | required | description | 
| ------ | --------- |  --------- | --------- | 
| oid | int | Y    | 订单id    | 

Function:
读取数据库中的oid订单状态，根据情况更新订单调度表

Return:
Void

#### cm_driver_update

Request:

|  Parameter   | type | required | description | 
| ------ | --------- |  --------- | --------- | 
| driver_id | int | Y  |  司机id    | 
| new_lat | decimal | Y | 最新位置的latitude   | 
| new_lng | decimal | Y | 最新位置的longitude  | 

Function:
更新司机位置，根据情况更新订单调度表

Return:
Void


#### cm_get_schedule

Request:

|  Parameter   | type | required | description | 
| ------ | --------- |  --------- | --------- | 
| driver_id | int | Y  |  司机id    | 

Function:
获取当前调度表

Return:

|  Schedule   | type | required | description | 
| ------ | --------- |  --------- | --------- | 
| driver_id | int | Y  |  司机id    | 
| tasks |  array | Y  |      | 

|  tasks  | type | required | description | 
| ------ | --------- |  --------- | --------- | 
| oid | int | Y  |  order id    | 
| type |  string | Y  | "pickup" or "delivery"   |
| addr |  string | Y  |    |
| arriveTime |  int | Y  |    |
| completeTime |  int | Y  |    |
| readyTime |  int | Y  |    |
| deadlineTime |  int | Y  |    |
| reward |  double | Y  |    |


