Imports MySql.Data.MySqlClient
Module Module1
    Public Sub DbConnect()
        Dim conn As New MysqlConnection
        Dim dbname As String = "it2adb"
        Dim username As String = "root"
        Dim password As String = "password"
        Dim server As String = "127.0.0.1"

        If Not conn Is Nothing Then
            conn.Close()
            'establish new connection
            conn.ConnectionString = "server = & server & ;user id = " & username & ";password=" & password & ";database=" & dbname & ""

            Try
                conn.Open()
                MsgBox("connected")
            Catch ex As Exception
                MsgBox(ex.Message)

            Catch ex As Exception

            End Try

        End If
    End Sub
End Module
