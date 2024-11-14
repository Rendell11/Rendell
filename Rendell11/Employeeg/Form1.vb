Public Class Form1
    Dim sqlQuery As String
    ReadOnly dataAdaptor As MysqlDataAdapter
    Dim dt As DataTable
    Private Sub Form1_Load(sender As Object, e As EventArgs) Handles MyBase.Load
        DbConnect()
    End Sub

    Private Sub btnclose_Click(sender As Object, e As EventArgs) Handles btnclose.Click

    End Sub

    Private Sub btnload_Click(sender As Object, e As EventArgs) Handles btnload.Click
        Try
            sqlQuery = "select"
            da = New MysqlDataAdapter(sqlQuery, conn)
            dt = New DataTable

            da.Fill(dt)
            IDataRecord.DataSource

        Catch ex As Exception

        End Try
    End Sub